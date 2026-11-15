<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskRegisterImport;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterCsv;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterSync;

/**
 * Issue #602 : import CSV d'un registre des risques, réimport sans doublon, export et aller-retour
 * (données fictives). Les valeurs du fichier sont des clés internes ou des notes : le test ne dépend
 * pas de la langue de l'instance (anglais en CI, français en développement).
 */
final class RiskRegisterImportTest extends GrcmanagerIntegrationTestCase
{
    private string $suffix;
    private string $login;
    private string $computer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = (string) random_int(100000, 999999);
        $userId = $this->createTestUser('Alice', 'Demo' . $this->suffix, [
            '_useremails' => ['-1' => 'alice.demo' . $this->suffix . '@example.org'],
        ]);
        (new \Profile_User())->add([
            'users_id' => $userId, 'profiles_id' => 4, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $this->login = $this->userLogin($userId);
        $this->computer = 'PHPU-SRV-' . $this->suffix;
        (new \Computer())->add([
            'name' => $this->computer, 'entities_id' => 0, 'otherserial' => 'INV-' . $this->suffix,
        ]);
    }

    private function userLogin(int $userId): string
    {
        $user = new \User();
        $user->getFromDB($userId);

        return (string) $user->fields['name'];
    }

    /**
     * @return array{errors: list<array<string, mixed>>, result: array<string, mixed>}
     */
    private function import(string $csv, int $scale = 4, bool $canCreate = true, bool $canUpdate = true): array
    {
        $parsed = RiskRegisterCsv::parse(RiskRegisterCsv::toUtf8($csv), RiskRegisterImport::lookups(true), $scale);
        $plan = RiskRegisterImport::filterRights(
            RiskRegisterSync::plan(RiskRegisterImport::existing(), $parsed['rows']),
            $canCreate,
            $canUpdate
        );

        return [
            'errors' => array_merge($parsed['errors'], $plan['errors']),
            'result' => RiskRegisterImport::apply($plan['steps']),
        ];
    }

    private function risk(string $reference): Risk
    {
        $risk = new Risk();
        $this->assertTrue($risk->getFromDBByCrit(['external_ref' => $reference]), $reference);

        return $risk;
    }

    private function countRisks(): int
    {
        return countElementsInTable(Risk::getTable(), ['external_ref' => ['LIKE', 'PHPU-' . $this->suffix . '-%']]);
    }

    private function firstVersion(): string
    {
        $ref = 'PHPU-' . $this->suffix;

        return "reference;title;description;category;probability;impact;treatment;owner;review_date;status;"
            . "annex_a;assets\n"
            . "$ref-01;Vol de portable;Disque non chiffré;technical;3;4;mitigate;{$this->login};31/12/2026;;"
            . "A.8.1 A.8.24;{$this->computer}\n"
            . "$ref-02;Panne de l'hébergeur;;third_party;2;3;transfer;alice.demo{$this->suffix}@example.org;;;A.5.19;\n"
            . "$ref-03;Hameçonnage ciblé;;people;probable;high;;Alice Demo{$this->suffix};;in_treatment;;"
            . "INV-{$this->suffix}\n"
            . "$ref-04;Propriétaire inconnu;;process;1;1;;nobody.phpu{$this->suffix};;;;\n"
            . "$ref-05;Mesure inconnue;;process;1;1;;;;;A.99.9;\n";
    }

    public function testImportThenReimportUpdatesWithoutDuplicatesAndKeepsWhatTheFileDoesNotSay(): void
    {
        $ref = 'PHPU-' . $this->suffix;
        $first = $this->import($this->firstVersion());

        $this->assertSame(
            [RiskRegisterCsv::ERROR_UNKNOWN_OWNER, RiskRegisterCsv::ERROR_UNKNOWN_ANNEX_A],
            array_column($first['errors'], 'reason')
        );
        $this->assertSame([5, 6], array_column($first['errors'], 'line'));
        $this->assertSame(3, $first['result']['create']);
        $this->assertSame([], $first['result']['failed']);
        $this->assertSame(3, $this->countRisks());

        $risk = $this->risk("$ref-01");
        $this->assertSame('probable', $risk->fields['probability']);
        $this->assertSame('critical', $risk->fields['impact']);
        $this->assertNotSame('', (string) $risk->fields['risk_level'], 'Niveau calculé par la matrice.');
        $this->assertSame('2026-12-31', $risk->fields['review_date']);
        $this->assertSame('identified', $risk->fields['status'], 'Statut absent : valeur par défaut.');
        $this->assertSame(['A.8.1', 'A.8.24'], $this->controlsOf((int) $risk->getID()));
        $assets = Risk::getLinkedAssets((int) $risk->getID());
        $this->assertCount(1, $assets);
        $this->assertSame($this->computer, $assets[0]['name']);
        $alice = (int) $risk->fields['users_id'];
        $this->assertGreaterThan(0, $alice);
        $this->assertSame($alice, (int) $this->risk("$ref-02")->fields['users_id'], 'Rapprochement par e-mail.');
        $this->assertSame($alice, (int) $this->risk("$ref-03")->fields['users_id'], 'Rapprochement par nom.');
        $this->assertSame('in_treatment', $this->risk("$ref-03")->fields['status']);
        $this->assertCount(1, Risk::getLinkedAssets((int) $this->risk("$ref-03")->getID()), 'Numéro d\'inventaire.');

        // Saisie dans GLPI entre deux imports : une justification que le fichier ne connaît pas.
        $this->assertTrue((new Risk())->update([
            'id' => $this->risk("$ref-02")->getID(), 'justification' => 'Saisie dans GLPI',
        ]));

        // Réimport : fichier modifié, sans colonnes description ni justification, cellule vide.
        $second = $this->import(
            "Référence;Titre;Gravité;Statut\n"
            . "$ref-01;Vol de portable;2;\n"
            . "$ref-02;Panne de l'hébergeur;;\n"
            . "$ref-06;Nouveau risque;1;\n"
        );
        $this->assertSame([], $second['errors']);
        $this->assertSame(1, $second['result']['create']);
        $this->assertSame(1, $second['result']['update']);
        $this->assertSame(1, $second['result']['unchanged']);
        $this->assertSame(4, $this->countRisks(), 'Aucun doublon.');
        $risk = $this->risk("$ref-01");
        $this->assertSame('medium', $risk->fields['impact']);
        $this->assertSame('Disque non chiffré', $risk->fields['description'], 'Colonne absente : valeur gardée.');
        $this->assertSame('identified', $risk->fields['status']);
        $this->assertCount(1, Risk::getLinkedAssets((int) $risk->getID()), 'Colonne absente : liens gardés.');
        $this->assertSame('Saisie dans GLPI', $this->risk("$ref-02")->fields['justification']);
    }

    /**
     * @return list<string>
     */
    private function controlsOf(int $riskId): array
    {
        global $DB;

        $codes = [];
        $rows = $DB->request([
            'SELECT'     => ['controls.code'],
            'FROM'       => 'glpi_plugin_grcmanager_controls_risks AS links',
            'INNER JOIN' => [
                Control::getTable() . ' AS controls' => [
                    'FKEY' => ['links' => 'plugin_grcmanager_controls_id', 'controls' => 'id'],
                ],
            ],
            'WHERE'      => ['links.plugin_grcmanager_risks_id' => $riskId],
            'ORDER'      => 'controls.code',
        ]);
        foreach ($rows as $row) {
            $codes[] = (string) $row['code'];
        }

        return $codes;
    }

    public function testWindows1252CommaSeparatedFileAndFiveLevelScale(): void
    {
        $ref = 'PHPU-' . $this->suffix;
        $csv = mb_convert_encoding(
            "Référence,Titre,Probabilité,Gravité\n$ref-01,Accès réseau non autorisé,5,1\n$ref-02,Écran visible,3,4\n",
            'Windows-1252',
            'UTF-8'
        );

        $import = $this->import($csv, 5);

        $this->assertSame([], $import['errors']);
        $this->assertSame('Accès réseau non autorisé', $this->risk("$ref-01")->fields['title']);
        $this->assertSame('certain', $this->risk("$ref-01")->fields['probability']);
        $this->assertSame('low', $this->risk("$ref-01")->fields['impact']);
        $this->assertSame('possible', $this->risk("$ref-02")->fields['probability']);
        $this->assertSame('high', $this->risk("$ref-02")->fields['impact']);
    }

    public function testExportReadsBackWithoutAnyChange(): void
    {
        $this->import($this->firstVersion());
        $risk = $this->risk('PHPU-' . $this->suffix . '-02');
        // Texte qui ressemble à une formule : neutralisé à l'export, retrouvé tel quel à l'import.
        $this->assertTrue((new Risk())->update(['id' => $risk->getID(), 'justification' => '=1+1 (texte)']));
        $glpiRisk = (int) (new Risk())->add(['title' => 'PHPU saisi dans GLPI ' . $this->suffix]);
        $this->assertGreaterThan(0, $glpiRisk);

        $content = RiskRegisterCsv::build(
            array_merge(RiskRegisterCsv::COLUMNS, RiskRegisterCsv::COMPUTED_COLUMNS),
            RiskRegisterImport::exportRows()
        );
        $this->assertStringContainsString(";'=1+1 (texte);", $content);
        $this->assertStringContainsString(RiskRegisterCsv::INTERNAL_PREFIX . $glpiRisk . ';', $content);
        $total = countElementsInTable(Risk::getTable());

        $parsed = RiskRegisterCsv::parse($content, RiskRegisterImport::lookups(true));
        $plan = RiskRegisterSync::plan(RiskRegisterImport::existing(), $parsed['rows']);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([], $plan['errors']);
        $this->assertSame(0, RiskRegisterSync::summary($plan['steps'])[RiskRegisterSync::CREATE]);
        $this->assertSame(
            [],
            array_column(array_filter(
                $plan['steps'],
                static fn (array $step): bool => $step['action'] !== RiskRegisterSync::UNCHANGED
            ), 'changes', 'reference')
        );
        RiskRegisterImport::apply($plan['steps']);
        $this->assertSame($total, countElementsInTable(Risk::getTable()));
    }

    public function testOwnersAndAssetsOutsideTheSessionEntitiesAreNotFound(): void
    {
        $entity = $this->createTestEntity(0, 'PHPU entité B ' . $this->suffix);
        $bobId = $this->createTestUser('Bob', 'Hors' . $this->suffix);
        global $DB;
        // Seulement dans l'entité B (sans le profil par défaut éventuellement posé à la racine).
        $DB->delete('glpi_profiles_users', ['users_id' => $bobId]);
        (new \Profile_User())->add(['users_id' => $bobId, 'profiles_id' => 4, 'entities_id' => $entity]);
        (new \Computer())->add(['name' => 'PHPU-HORS-' . $this->suffix, 'entities_id' => $entity]);
        // La session ne voit plus l'entité B.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$entity]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        $ref = 'PHPU-' . $this->suffix;

        $import = $this->import(
            "reference;title;owner;assets\n"
            . "$ref-01;Propriétaire hors périmètre;{$this->userLogin($bobId)};\n"
            . "$ref-02;Actif hors périmètre;;PHPU-HORS-{$this->suffix}\n"
        );

        $this->assertSame(
            [RiskRegisterCsv::ERROR_UNKNOWN_OWNER, RiskRegisterCsv::ERROR_UNKNOWN_ASSET],
            array_column($import['errors'], 'reason')
        );
        $this->assertSame(0, $this->countRisks());
    }

    public function testRightsLimitWhatTheImportMayDo(): void
    {
        $ref = 'PHPU-' . $this->suffix;
        $this->import("reference;title\n$ref-01;Existant\n");

        // Modification seule : la création est refusée, la mise à jour passe.
        $import = $this->import("reference;title;impact\n$ref-01;Existant;4\n$ref-02;Nouveau;1\n", 4, false, true);
        $this->assertSame(['no_create_right'], array_column($import['errors'], 'reason'));
        $this->assertSame(1, $import['result']['update']);
        $this->assertSame(1, $this->countRisks());

        // Création seule : la mise à jour est refusée.
        $import = $this->import("reference;title;impact\n$ref-01;Existant;1\n", 4, true, false);
        $this->assertSame(['no_update_right'], array_column($import['errors'], 'reason'));
        $this->assertSame('critical', $this->risk("$ref-01")->fields['impact']);
    }
}

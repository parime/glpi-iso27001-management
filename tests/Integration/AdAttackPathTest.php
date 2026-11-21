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

use GlpiPlugin\Grcmanager\AdAttackPath;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Install\AdAttackPathTables as Tables;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskIndicator;

/**
 * Issue #835, lot 3 : chemins d'attaque vers Domain Admins (export BloodHound CE fictif) importés dans
 * une entité de test, évolution, risque créé sur clic, preuve, KRI et cloisonnement par entité.
 */
final class AdAttackPathTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $hidden;

    private string $domain;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $suffix = (string) random_int(1000, 9999);
        $this->domain = 'paths' . $suffix . '.exemple.test';
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Chemins ' . $suffix);
        $this->hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale chemins ' . $suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = [];
        $risks = [];
        foreach ($DB->request(['FROM' => Tables::IMPORTS, 'WHERE' => ['domain' => $this->domain]]) as $row) {
            $ids[] = (int) $row['id'];
            $risks[] = (int) $row['plugin_grcmanager_risks_id'];
        }
        if ($ids !== []) {
            $DB->delete(Tables::ACCOUNTS, [AdAttackPath::FK => $ids]);
            $DB->delete(Tables::IMPORTS, ['id' => $ids]);
        }
        foreach (array_unique(array_filter($risks)) as $riskId) {
            (new Risk())->delete(['id' => $riskId], true);
        }
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        parent::tearDown();
    }

    /**
     * Export d'exemple pour le domaine du test ; $extra ajoute un compte relié directement au groupe.
     */
    private function export(string $extra = ''): string
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/bloodhound-example.json');
        $json = str_replace('@EXEMPLE.TEST', '@' . strtoupper($this->domain), $json);
        if ($extra !== '') {
            $json = str_replace(
                '"edges": [',
                '"edges": [{"source": "99", "target": "1", "kind": "AddMember"},',
                $json
            );
            $json = str_replace(
                '"nodes": {',
                '"nodes": {"99": {"label": "' . $extra . '@' . strtoupper($this->domain) . '", "kind": "User"},',
                $json
            );
        }

        return $json;
    }

    public function testImportEvolutionAndRisk(): void
    {
        $first = AdAttackPath::import($this->export(), 'bloodhound.json');
        $this->assertSame('', $first['error']);
        $this->assertSame(6, $first['paths']);
        $this->assertSame('duplicate', AdAttackPath::import($this->export(), 'bloodhound.json')['error']);

        $detail = AdAttackPath::present((array) AdAttackPath::getImport($first['id']), true);
        $this->assertSame($this->domain, $detail['domain']);
        $this->assertNull($detail['trend']);
        $this->assertFalse($detail['has_previous']);
        $this->assertCount(6, $detail['accounts']);
        $this->assertSame('', $detail['risk_url']);

        // Risque créé sur clic, une seule fois.
        $riskId = AdAttackPath::sendToRegister($first['id']);
        $this->assertGreaterThan(0, $riskId);
        $this->assertSame($riskId, AdAttackPath::sendToRegister($first['id']));
        $risk = new Risk();
        $this->assertTrue($risk->getFromDB($riskId));
        $this->assertSame($this->entity, (int) $risk->fields['entities_id']);
        $this->assertStringContainsString($this->domain, (string) $risk->fields['title']);

        // Nouvel export : un compte de plus, risque repris.
        $second = AdAttackPath::import($this->export('NOUVEAU.COMPTE'), 'bloodhound-2.json');
        $this->assertSame('', $second['error']);
        $this->assertSame(7, $second['paths']);
        $detail = AdAttackPath::present((array) AdAttackPath::getImport($second['id']), true);
        $this->assertSame(1, $detail['trend']);
        $this->assertNotSame('', $detail['risk_url']);
        $new = array_values(array_filter($detail['accounts'], static fn (array $a): bool => $a['new']));
        $this->assertCount(1, $new);
        $this->assertSame('NOUVEAU.COMPTE@' . strtoupper($this->domain), $new[0]['name']);

        $sources = FeatureRegistry::riskIndicatorSources();
        $this->assertArrayHasKey('ad_attack_paths', $sources);
        $this->assertSame(7.0, AdAttackPath::worstPathCount([$this->entity]));
        $this->assertNull(AdAttackPath::worstPathCount([$this->hidden]));
        $this->assertArrayHasKey('ad_attack_paths', RiskIndicator::sourceDefinitions());

        // Preuve sur les mesures concernées seulement.
        $evidence = AdAttackPath::evidence('A.8.2');
        $this->assertNotNull($evidence);
        $this->assertSame(7, $evidence['domains'][0]['paths']);
        $this->assertNull(AdAttackPath::evidence('A.5.1'));
        $this->assertNotEmpty(FeatureRegistry::controlEvidence('A.5.18'));

        // Section de la page rendue.
        ob_start();
        AdAttackPath::showSection($second['id']);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-ad-path-accounts', $html);
        $this->assertStringContainsString('NOUVEAU.COMPTE', $html);

        $this->assertTrue(AdAttackPath::clear($first['id']));
        $this->assertNull(AdAttackPath::getImport($first['id']));
    }

    public function testErrorsAndEntityIsolation(): void
    {
        global $DB;

        $this->assertSame('no_target', AdAttackPath::import('{"nodes": {"1": {"label": "x", "kind": "User"}}, "edges": []}', 'a.json')['error']);
        $this->assertSame('invalid', AdAttackPath::import('{"nodes": [', 'a.json')['error']);
        $noDomain = '{"elements": [{"data": {"id": "1", "label": "Domain Admins", "type": "Group", "target": true}}]}';
        $this->assertSame('no_domain', AdAttackPath::import($noDomain, 'a.json')['error']);
        $withHint = AdAttackPath::import($noDomain, 'a.json', $this->domain);
        $this->assertSame('', $withHint['error']);
        $this->assertSame(0, $withHint['paths']);
        $this->assertSame(0, AdAttackPath::sendToRegister($withHint['id']), 'No risk without any path.');

        $DB->insert(Tables::IMPORTS, [
            'entities_id' => $this->hidden, 'tool' => 'bloodhound', 'domain' => $this->domain, 'path_count' => 50,
            'date_import' => date('Y-m-d H:i:s'), 'file_sha256' => hash('sha256', $this->domain . 'hidden'),
        ]);
        $hiddenId = (int) $DB->insertId();
        $this->assertNull(AdAttackPath::getImport($hiddenId));
        $this->assertFalse(AdAttackPath::clear($hiddenId));
        $this->assertSame(0, AdAttackPath::sendToRegister($hiddenId));
        $this->assertNotContains($hiddenId, array_map('intval', array_column(AdAttackPath::imports(), 'id')));
    }
}

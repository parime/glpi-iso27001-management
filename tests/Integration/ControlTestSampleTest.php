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

use CommonITILObject;
use Computer;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\ControlTestSample;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\SampleRules;

/**
 * Issue #607 : échantillon tiré dans GLPI pour un test d'efficacité (population visible seulement,
 * tirage reproductible et figé, résultat par élément, résultat proposé) et relecture du test par
 * une seconde personne. Données fictives (ordinateurs « PC Exemple »). Indépendant de la langue de
 * l'instance : aucune comparaison de texte traduit.
 */
final class ControlTestSampleTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
    }

    protected function tearDown(): void
    {
        ControlTest::saveReviewEnabled('0');
        $_SESSION = $this->session;
        parent::tearDown();
    }

    private function control(string $code, string $status = 'implemented'): Control
    {
        global $DB;

        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code, 'entities_id' => 0]));
        $DB->update(Control::getTable(), [
            'implementation_status' => $status,
            'applicability'         => 'yes',
            'test_frequency_months' => 12,
        ], ['id' => $control->getID()]);
        $control->getFromDB($control->getID());

        return $control;
    }

    /**
     * Entité fictive active seule, avec ses ordinateurs ; un ordinateur d'une autre entité, hors
     * des entités actives.
     *
     * @return array{entity: int, computers: list<int>, outside: int}
     */
    private function population(int $count): array
    {
        $entity = $this->createTestEntity(0, 'Société Exemple ' . random_int(1000, 9999));
        $other = $this->createTestEntity(0, 'Filiale Exemple ' . random_int(1000, 9999));
        $computers = [];
        for ($i = 1; $i <= $count; $i++) {
            $computers[] = (int) (new Computer())->add([
                'name' => sprintf('PC Exemple %02d', $i), 'entities_id' => $entity,
            ]);
        }
        $outside = (int) (new Computer())->add(['name' => 'PC Filiale Exemple', 'entities_id' => $other]);
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string) $entity;
        $_SESSION['glpiactive_entity'] = $entity;

        return ['entity' => $entity, 'computers' => $computers, 'outside' => $outside];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function draw(Control $control, array $extra = []): int
    {
        return ControlTestSample::draw($control, $extra + [
            'name'                => 'Chaque poste a-t-il un chiffrement actif ?',
            'itemtype'            => 'Computer',
            'operating_frequency' => 'weekly',
            'seed'                => 'audit-exemple-2026',
        ]);
    }

    /**
     * @return list<int>
     */
    private function drawnIds(int $sampleId): array
    {
        return array_map(static fn (array $row): int => (int) $row['items_id'], ControlTestSample::getItems($sampleId));
    }

    public function testDrawIsReproducibleFrozenAndLimitedToVisibleItems(): void
    {
        $data = $this->population(12);
        $control = $this->control('A.8.24');

        $first = $this->draw($control);
        $this->assertGreaterThan(0, $first);
        $sample = ControlTestSample::getSample($first);
        $this->assertNotNull($sample);
        $this->assertSame(12, (int) $sample['population_count']);
        $this->assertSame(SampleRules::FREQUENCY_SIZES['weekly'], (int) $sample['sample_size']);
        $this->assertSame('audit-exemple-2026', $sample['seed']);
        $this->assertSame(SampleRules::METHOD, $sample['draw_method']);
        $this->assertSame((int) $_SESSION['glpiID'], (int) $sample['users_id']);
        $this->assertNotEmpty($sample['date_draw']);

        $ids = $this->drawnIds($first);
        $this->assertCount(5, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'Aucun doublon.');
        $this->assertSame([], array_diff($ids, $data['computers']), 'Seulement des ordinateurs visibles.');
        $this->assertSame(SampleRules::draw($data['computers'], 5, 'audit-exemple-2026'), $ids);
        $labels = array_column(ControlTestSample::getItems($first), 'label');
        $this->assertStringStartsWith('PC Exemple', (string) $labels[0], 'Nom figé au tirage.');

        // Même graine : même échantillon. Sans graine : une graine aléatoire est conservée.
        $this->assertSame($ids, $this->drawnIds($this->draw($control)));
        $random = $this->draw($control, ['seed' => '']);
        $seed = (string) (ControlTestSample::getSample($random)['seed'] ?? '');
        $this->assertMatchesRegularExpression(SampleRules::SEED_PATTERN, $seed);

        // Toute la population : jamais l'ordinateur de l'entité qui n'est pas active.
        $all = $this->drawnIds($this->draw($control, ['sample_size' => 100]));
        $this->assertCount(12, $all);
        $this->assertNotContains($data['outside'], $all);

        // Entité hors des entités actives, période incohérente, graine invalide : rien n'est tiré.
        $before = count(ControlTestSample::getForControl((int) $control->getID()));
        $this->assertSame(0, $this->draw($control, ['entities_id' => 999999]));
        $this->assertSame(0, $this->draw($control, ['period_start' => '2026-04-01', 'period_end' => '2026-03-01']));
        $this->assertSame(0, $this->draw($control, ['seed' => 'a b']));
        $this->assertSame(0, $this->draw($control, ['itemtype' => 'Profile']));
        $this->assertSame(0, $this->draw($control, ['name' => '  ']));
        // Période sans aucun élément créé.
        $this->assertSame(0, $this->draw($control, ['period_start' => '2001-01-01', 'period_end' => '2001-12-31']));
        $this->assertCount($before, ControlTestSample::getForControl((int) $control->getID()));
    }

    public function testTicketsRequireTheRightToSeeThemAll(): void
    {
        $this->population(3);
        $control = $this->control('A.8.32');
        $_SESSION['glpiactiveprofile']['ticket'] = CommonITILObject::READMY;
        $_SESSION['glpiactiveprofile']['change'] = CommonITILObject::READMY;
        $this->assertFalse(ControlTestSample::canDrawFrom('Ticket'));
        $this->assertSame(0, $this->draw($control, ['itemtype' => 'Ticket']));
        $this->assertSame(0, $this->draw($control, ['itemtype' => 'Change']));

        $_SESSION['glpiactiveprofile']['computer'] = 0;
        $this->assertSame(0, $this->draw($control), 'Sans lecture des ordinateurs.');

        // Sans le droit de modifier la mesure, aucun tirage.
        $_SESSION['glpiactiveprofile']['computer'] = READ;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $this->assertSame(0, $this->draw($control));
    }

    public function testResultsPerItemProposedResultAndRecording(): void
    {
        global $DB;

        $this->population(8);
        $control = $this->control('A.8.13');
        $sampleId = $this->draw($control, ['sample_size' => 4]);
        $sample = ControlTestSample::getSample($sampleId);
        $this->assertNotNull($sample);
        $items = ControlTestSample::getItems($sampleId);
        $this->assertCount(4, $items);

        // Tant qu'un élément reste à évaluer, le test ne s'enregistre pas.
        $this->assertSame(0, ControlTestSample::record($sample, []));

        $results = [];
        foreach ($items as $item) {
            $results[(int) $item['id']] = ['result' => 'compliant', 'comment' => ''];
        }
        $lastId = (int) $items[3]['id'];
        $results[$lastId] = ['result' => 'noncompliant', 'comment' => ''];
        // Un élément d'un autre échantillon est ignoré.
        $results[999999999] = ['result' => 'compliant'];
        $this->assertSame(4, ControlTestSample::saveResults($sample, $results));
        $this->assertSame(0, ControlTestSample::record($sample, []), 'Écart sans commentaire.');

        $results[$lastId]['comment'] = 'Chiffrement désactivé sur ce poste fictif';
        ControlTestSample::saveResults($sample, $results);
        $tally = SampleRules::tally(ControlTestSample::getItems($sampleId));
        // 3 conformes sur 4 : 75 %, sous le seuil, inefficace.
        $this->assertSame(ControlTestRules::RESULT_INEFFECTIVE, SampleRules::proposedResult($tally));

        // Autre résultat que celui proposé : justification exigée.
        $this->assertSame(0, ControlTestSample::record($sample, ['result' => 'partial']));
        $testsId = ControlTestSample::record($sample, [
            'result'               => 'partial',
            'result_justification' => 'Poste fictif remis en conformité pendant le test',
            'test_date'            => date('Y-m-d'),
        ]);
        $this->assertGreaterThan(0, $testsId);

        $test = new ControlTest();
        $this->assertTrue($test->getFromDB($testsId));
        $this->assertSame('sampling', $test->fields['method']);
        $this->assertSame('partial', $test->fields['result']);
        $this->assertSame((int) $_SESSION['glpiID'], (int) $test->fields['users_id_recorder']);
        $this->assertSame('', $test->fields['review_status']);

        $frozen = ControlTestSample::getSample($sampleId);
        $this->assertNotNull($frozen);
        $this->assertSame($testsId, (int) $frozen['plugin_grcmanager_controltests_id']);
        $this->assertSame('ineffective', $frozen['proposed_result']);
        $this->assertNotSame('', $frozen['result_justification']);
        $this->assertSame($sampleId, (int) (ControlTestSample::getForTest($testsId)['id'] ?? 0));

        // Figé : ni nouveaux résultats, ni second test, ni suppression de l'échantillon.
        $this->assertSame(0, ControlTestSample::saveResults($frozen, [$lastId => ['result' => 'compliant']]));
        $this->assertSame(0, ControlTestSample::record($frozen, []));
        $this->assertFalse(ControlTestSample::purge($frozen));

        // Fiche PDF du test : l'échantillon y figure, élément par élément.
        $html = ControlTest::renderRecord($test);
        $this->assertStringContainsString('audit-exemple-2026', $html);
        $this->assertStringContainsString('Chiffrement désactivé sur ce poste fictif', $html);
        $this->assertStringContainsString((string) $items[0]['label'], $html);

        // Supprimer le test rouvre l'échantillon, résultats conservés.
        $this->assertTrue($test->delete(['id' => $testsId], true));
        $reopened = ControlTestSample::getSample($sampleId);
        $this->assertNotNull($reopened);
        $this->assertTrue(ControlTestSample::isOpen($reopened));
        $this->assertSame(4, countElementsInTable(ControlTestSample::ITEMS_TABLE, [
            'plugin_grcmanager_controltestsamples_id' => $sampleId,
            'NOT' => ['result' => ''],
        ]));

        // Un échantillon ouvert se supprime avec ses éléments.
        $this->assertTrue(ControlTestSample::purge($reopened));
        $this->assertNull(ControlTestSample::getSample($sampleId));
        $this->assertSame(0, countElementsInTable(ControlTestSample::ITEMS_TABLE, [
            'plugin_grcmanager_controltestsamples_id' => $sampleId,
        ]));
        $this->assertSame(0, (int) $DB->request([
            'COUNT' => 'cpt', 'FROM' => ControlTestSample::TABLE, 'WHERE' => ['id' => $sampleId],
        ])->current()['cpt']);
    }

    public function testSamplePageShowsHowTheSampleWasDrawn(): void
    {
        $this->population(3);
        $control = $this->control('A.8.7');
        $sample = ControlTestSample::getSample($this->draw($control));
        $this->assertNotNull($sample);

        ob_start();
        ControlTestSample::showPage($sample, $control);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-sample-seed', $html);
        $this->assertStringContainsString('audit-exemple-2026', $html);
        $this->assertStringContainsString('PC Exemple', $html);
        $this->assertStringContainsString('name="record"', $html);

        // En lecture seule : la liste, sans formulaire.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        ob_start();
        ControlTestSample::showPage($sample, $control);
        $readonly = (string) ob_get_clean();
        $this->assertStringContainsString('PC Exemple', $readonly);
        $this->assertStringNotContainsString('name="record"', $readonly);
    }

    public function testReviewBySecondPersonBeforeVerified(): void
    {
        $control = $this->control('A.5.15');
        ControlTest::saveReviewEnabled('1');
        $this->assertTrue(ControlTest::isReviewEnabled());

        $testsId = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => date('Y-m-d'),
            'method'                        => 'inspection',
            'result'                        => 'effective',
        ]);
        $this->assertGreaterThan(0, $testsId);
        $test = new ControlTest();
        $test->getFromDB($testsId);
        $this->assertSame(ControlTestRules::REVIEW_PENDING, $test->fields['review_status']);
        $control->getFromDB($control->getID());
        $this->assertSame('implemented', $control->fields['implementation_status'], 'Pas encore vérifiée.');

        // Le testeur ne relit pas son propre test.
        $this->assertFalse(ControlTest::canReviewTest($test->fields));
        $this->assertFalse(ControlTest::review($testsId, true, ''));

        $reviewer = $this->createTestUser('Relecteur', 'Exemple');
        $_SESSION['glpiID'] = $reviewer;
        $this->assertTrue(ControlTest::canReviewTest($test->fields));
        $this->assertFalse(ControlTest::review($testsId, false, '  '), 'Renvoyer exige un commentaire.');
        $this->assertTrue(ControlTest::review($testsId, true, 'Preuves relues'));

        $test->getFromDB($testsId);
        $this->assertSame(ControlTestRules::REVIEW_APPROVED, $test->fields['review_status']);
        $this->assertSame($reviewer, (int) $test->fields['users_id_reviewer']);
        $this->assertNotEmpty($test->fields['review_date']);
        $control->getFromDB($control->getID());
        $this->assertSame('verified', $control->fields['implementation_status']);
        $this->assertFalse(ControlTest::review($testsId, true, ''), 'Déjà relu.');

        // Un test renvoyé ne compte pas ; un test inefficace retire « vérifié » sans attendre.
        $_SESSION['glpiID'] = 2;
        $second = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'ineffective',
            'comment'                       => 'Constat fictif',
        ]);
        $control->getFromDB($control->getID());
        $this->assertSame('implemented', $control->fields['implementation_status']);
        $_SESSION['glpiID'] = $reviewer;
        $this->assertTrue(ControlTest::review($second, false, 'Constat à préciser'));
        $test->getFromDB($second);
        $this->assertSame(ControlTestRules::REVIEW_RETURNED, $test->fields['review_status']);

        // Sans le droit de modifier la mesure, pas de relecture.
        $third = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'effective',
            'users_id'                      => 2,
        ]);
        $test->getFromDB($third);
        $this->assertSame($reviewer, (int) $test->fields['users_id_recorder'], 'Enregistré par le relecteur.');
        $this->assertFalse(ControlTest::canReviewTest($test->fields), 'Ni par qui l\'a enregistré.');
        $_SESSION['glpiID'] = (int) $this->createTestUser('Lecteur', 'Exemple');
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $this->assertFalse(ControlTest::canReviewTest($test->fields));
    }
}

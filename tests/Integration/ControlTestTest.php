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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Nonconformity;

/**
 * Issue #380 : tests d'efficacité des mesures (plan, enregistrement, preuve, non-conformité, état
 * « vérifié » de la SoA, échéances du calendrier de conformité, droits de la mesure).
 */
final class ControlTestTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
        // Session réelle : entité active (listes déroulantes des utilisateurs de l'onglet).
        $_SESSION['glpiactive_entity'] ??= 0;
    }

    protected function tearDown(): void
    {
        // Droits et entités modifiés par certains tests : jamais transmis aux suivants.
        $_SESSION = $this->session;
        parent::tearDown();
    }
    private function control(string $code, string $status = 'implemented', int $frequency = 12): Control
    {
        global $DB;

        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]));
        $DB->update(Control::getTable(), [
            'implementation_status' => $status,
            'applicability'         => 'yes',
            'is_reviewed'           => 0,
            'test_frequency_months' => $frequency,
        ], ['id' => $control->getID()]);
        $control->getFromDB($control->getID());

        return $control;
    }

    private function attachDocument(Control $control, string $name, int $entitiesId = 0): int
    {
        $documentsId = (int) (new Document())->add(['name' => $name, 'entities_id' => $entitiesId]);
        $this->assertGreaterThan(0, $documentsId);
        (new Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => Control::class,
            'items_id'     => $control->getID(),
        ]);

        return $documentsId;
    }

    public function testTestPlanIsSavedWithoutMarkingTheSoaReviewed(): void
    {
        $control = $this->control('A.5.15', 'implemented', 0);
        $this->assertTrue($control->update([
            'id'                    => $control->getID(),
            'test_frequency_months' => '6',
            'test_method'           => 'sampling',
            'users_id_tester'       => 2,
            '_grcmanager_from_test' => true,
        ]));
        $control->getFromDB($control->getID());
        $this->assertSame(6, (int) $control->fields['test_frequency_months']);
        $this->assertSame('sampling', $control->fields['test_method']);
        $this->assertSame(2, (int) $control->fields['users_id_tester']);
        $this->assertSame(0, (int) $control->fields['is_reviewed']);

        // Valeurs hors liste ramenées à « aucun plan ».
        $control->update([
            'id'                    => $control->getID(),
            'test_frequency_months' => 7,
            'test_method'           => 'magie',
            '_grcmanager_from_test' => true,
        ]);
        $control->getFromDB($control->getID());
        $this->assertSame(0, (int) $control->fields['test_frequency_months']);
        $this->assertSame('', $control->fields['test_method']);
    }

    public function testEffectiveTestWithEvidenceVerifiesTheControl(): void
    {
        $control = $this->control('A.8.13');
        $documentsId = $this->attachDocument($control, 'Rapport de restauration fictif');

        $test = new ControlTest();
        $id = (int) $test->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => '2026-10-15',
            'method'                        => 'technical',
            'result'                        => 'effective',
            'documents_id'                  => $documentsId,
            // Jamais repris de la saisie.
            'plugin_grcmanager_nonconformities_id' => 999,
        ]);
        $this->assertGreaterThan(0, $id);
        $test->getFromDB($id);
        $this->assertSame('2027-10-15', $test->fields['next_test_date'], 'Date du test plus la fréquence du plan.');
        $this->assertSame((int) $_SESSION['glpiID'], (int) $test->fields['users_id']);
        $this->assertSame($documentsId, (int) $test->fields['documents_id']);
        $this->assertSame(0, (int) $test->fields['plugin_grcmanager_nonconformities_id']);

        $control->getFromDB($control->getID());
        $this->assertSame('verified', $control->fields['implementation_status']);
        $this->assertSame(0, (int) $control->fields['is_reviewed'], 'Un test n\'est pas une revue de la SoA.');

        // Un test n'est pas modifiable, seulement supprimable.
        $this->assertFalse($test->update(['id' => $id, 'result' => 'ineffective']));
        $this->assertTrue($test->delete(['id' => $id], true));
        $this->assertSame([], ControlTest::getForControl((int) $control->getID()));
    }

    public function testInvalidTestsAreRefused(): void
    {
        $control = $this->control('A.5.17');
        $other = $this->control('A.5.18');
        $foreignDocument = $this->attachDocument($other, 'Preuve d\'une autre mesure');
        $base = ['plugin_grcmanager_controls_id' => $control->getID(), 'test_date' => '2026-10-01'];

        $this->assertFalse((new ControlTest())->add($base), 'Résultat obligatoire.');
        $this->assertFalse((new ControlTest())->add($base + ['result' => 'maybe']));
        $this->assertFalse(
            (new ControlTest())->add($base + ['result' => 'partial', 'comment' => '  ']),
            'Constat obligatoire si la mesure n\'est pas pleinement efficace.'
        );
        $this->assertFalse(
            (new ControlTest())->add($base + ['result' => 'effective', 'documents_id' => $foreignDocument]),
            'La preuve doit être jointe à cette mesure.'
        );
        $this->assertFalse((new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => date('Y-m-d', strtotime('+1 day')),
            'result'                        => 'effective',
        ]), 'Pas de test daté dans le futur.');
        $this->assertFalse(
            (new ControlTest())->add($base + ['result' => 'effective', 'next_test_date' => '2026-09-01']),
            'Prochain test après la date du test.'
        );
        $this->assertFalse((new ControlTest())->add([
            'plugin_grcmanager_controls_id' => 0,
            'result'                        => 'effective',
        ]));
        $this->assertSame([], ControlTest::getForControl((int) $control->getID()));
    }

    public function testIneffectiveTestOpensANonconformityAndRemovesVerified(): void
    {
        $control = $this->control('A.8.7', 'verified');

        $test = new ControlTest();
        $id = (int) $test->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => '2026-10-20',
            'method'                        => 'sampling',
            'result'                        => 'ineffective',
            'comment'                       => '3 postes sur 10 sans antivirus à jour (échantillon fictif).',
            'next_test_date'                => '2027-01-20',
        ]);
        $this->assertGreaterThan(0, $id);
        $test->getFromDB($id);
        $this->assertSame('2027-01-20', $test->fields['next_test_date']);
        $ncId = (int) $test->fields['plugin_grcmanager_nonconformities_id'];
        $this->assertGreaterThan(0, $ncId);

        $nonconformity = new Nonconformity();
        $this->assertTrue($nonconformity->getFromDB($ncId));
        $this->assertStringContainsString('A.8.7', $nonconformity->fields['title']);
        $this->assertStringContainsString('3 postes sur 10', $nonconformity->fields['description']);
        $this->assertSame('nonconformity', $nonconformity->fields['finding_type']);
        $this->assertSame('open', $nonconformity->fields['status']);

        $control->getFromDB($control->getID());
        $this->assertSame('implemented', $control->fields['implementation_status']);
    }

    public function testWithoutTheRightToCreateANonconformityTheTestIsStillRecorded(): void
    {
        $control = $this->control('A.8.8');
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ | UPDATE;

        $test = new ControlTest();
        $id = (int) $test->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'ineffective',
            'comment'                       => 'Correctifs non appliqués (constat fictif).',
        ]);
        $this->assertGreaterThan(0, $id);
        $test->getFromDB($id);
        $this->assertSame(0, (int) $test->fields['plugin_grcmanager_nonconformities_id']);
        $this->assertSame(date('Y-m-d'), $test->fields['test_date']);
    }

    public function testRightsFollowTheControl(): void
    {
        $control = $this->control('A.5.1');
        $id = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'effective',
        ]);
        $this->assertGreaterThan(0, $id);

        // Lecture seule : rien ne s'enregistre ni ne se supprime.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $this->assertFalse(ControlTest::canEditControl((int) $control->getID()));
        $this->assertFalse((new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'effective',
        ]));
        $test = new ControlTest();
        $this->assertTrue($test->can($id, READ));
        $this->assertFalse($test->can($id, PURGE));
        $this->assertFalse(ControlTest::canCreate(), 'Pas de bouton « Ajouter » sur la liste.');

        // Sans droit sur le plugin : ni lecture ni onglet.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        $this->assertFalse((new ControlTest())->can($id, READ));
        $this->assertSame('', (new ControlTest())->getTabNameForItem($control));
        $this->assertFalse(ControlTest::displayTabContentForItem($control));
    }

    public function testTabShowsOnlyReadableEvidence(): void
    {
        $control = $this->control('A.5.36');
        $documentsId = $this->attachDocument($control, 'Rapport de revue fictif');
        (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => '2026-09-01',
            'result'                        => 'partial',
            'comment'                       => '<b>Constat</b> partiel',
            'documents_id'                  => $documentsId,
        ]);

        ob_start();
        $this->assertTrue(ControlTest::displayTabContentForItem($control));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Rapport de revue fictif', $html);
        $this->assertStringContainsString('&lt;b&gt;Constat&lt;/b&gt; partiel', $html);
        $this->assertStringNotContainsString('<b>Constat</b>', $html);
        $this->assertStringContainsString('controltest.form.php', $html);

        // Document d'une entité hors de portée : ni nom ni lien.
        $_SESSION['glpiactiveentities'] = [999999];
        $_SESSION['glpiactiveentities_string'] = '999999';
        ob_start();
        ControlTest::displayTabContentForItem($control);
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('Rapport de revue fictif', $html);
    }

    public function testScheduleFeedsTheComplianceCalendar(): void
    {
        $control = $this->control('A.7.4', 'implemented', 3);
        (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => '2026-08-10',
            'result'                        => 'effective',
        ]);

        $entries = array_values(array_filter(
            ControlTest::getSchedule(),
            static fn (array $entry): bool => (int) $entry['control']['id'] === (int) $control->getID()
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('2026-11-10', $entries[0]['next']);

        $events = array_values(array_filter(
            ComplianceEvent::collect('2026-11-01 00:00:00', '2026-12-01 00:00:00'),
            static fn (array $event): bool => $event['uid'] === 'control_test-' . $control->getID() . '@grcmanager'
        ));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('A.7.4', $events[0]['name']);
        $this->assertStringContainsString('forcetab=', $events[0]['url']);

        // Mesure non applicable : plus d'échéance.
        global $DB;
        $DB->update(Control::getTable(), ['applicability' => 'no'], ['id' => $control->getID()]);
        $this->assertSame([], array_filter(
            ControlTest::getSchedule(),
            static fn (array $entry): bool => (int) $entry['control']['id'] === (int) $control->getID()
        ));
    }
}

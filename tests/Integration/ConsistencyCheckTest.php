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

use Config;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #614 : contrôles de cohérence du SMSI sur des données fictives, droits et entités
 * (un élément illisible n'est jamais cité), réglages, export et tendance. Indépendant de la langue :
 * seuls les identifiants et les clés sont comparés.
 */
final class ConsistencyCheckTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, int>
     */
    private function seedInconsistencies(): array
    {
        global $DB;

        $ids = [];
        $DB->insert(Risk::getTable(), [
            'title' => 'Risque fictif sans propriétaire 614', 'status' => 'identified', 'users_id' => 0,
            'risk_level' => 'critical', 'treatment' => '', 'review_date' => date('Y-m-d', strtotime('-10 days')),
        ]);
        $ids['risk'] = (int) $DB->insertId();
        $DB->insert(Risk::getTable(), [
            'title' => 'Risque fictif accepté 614', 'status' => 'accepted', 'users_id' => 2,
            'risk_level' => 'high', 'treatment' => 'accept', 'users_id_acceptance' => 0,
            'review_date' => date('Y-m-d', strtotime('+100 days')),
        ]);
        $ids['risk_accepted'] = (int) $DB->insertId();
        $DB->insert(Risk::getTable(), [
            'title' => 'Risque fictif clos 614', 'status' => 'closed', 'users_id' => 0, 'risk_level' => 'critical',
        ]);
        $ids['risk_closed'] = (int) $DB->insertId();
        $DB->insert(Policy::getTable(), [
            'title' => 'Politique fictive 614', 'status' => 'approved', 'users_id' => 2,
            'next_review_date' => date('Y-m-d', strtotime('-30 days')),
        ]);
        $ids['policy'] = (int) $DB->insertId();
        $DB->insert(Nonconformity::getTable(), [
            'title' => 'Non-conformité fictive 614', 'status' => 'closed', 'finding_type' => 'nonconformity',
            'corrective_action' => '',
        ]);
        $ids['nonconformity'] = (int) $DB->insertId();
        $control = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['code' => 'A.5.7']])->current();
        $ids['control'] = (int) $control['id'];
        $DB->update(Control::getTable(), ['applicability' => 'yes', 'implementation_status' => 'implemented'], [
            'id' => $ids['control'],
        ]);
        $DB->delete('glpi_documents_items', ['itemtype' => Control::class, 'items_id' => $ids['control']]);
        $DB->delete('glpi_plugin_grcmanager_controltests', ['plugin_grcmanager_controls_id' => $ids['control']]);

        return $ids;
    }

    /**
     * @param array<string, array<string, mixed>> $results
     * @return list<int>
     */
    private function gapIds(array $results, string $rule): array
    {
        return array_map(static fn (array $gap): int => (int) $gap['id'], $results[$rule]['gaps']);
    }

    public function testRulesListTheFictitiousInconsistencies(): void
    {
        $ids = $this->seedInconsistencies();
        $results = ConsistencyCheck::run();

        $this->assertSame(array_keys(ConsistencyRules::RULES), array_keys($results));
        $this->assertContains($ids['risk'], $this->gapIds($results, 'risk_without_owner'));
        $this->assertNotContains($ids['risk_closed'], $this->gapIds($results, 'risk_without_owner'), 'risque clos');
        $this->assertContains($ids['risk'], $this->gapIds($results, 'risk_review_overdue'));
        $this->assertContains($ids['risk'], $this->gapIds($results, 'risk_without_decision'));
        $this->assertContains($ids['risk_accepted'], $this->gapIds($results, 'risk_accepted_without_approval'));
        $this->assertNotContains($ids['risk_accepted'], $this->gapIds($results, 'risk_review_overdue'));
        $this->assertContains($ids['policy'], $this->gapIds($results, 'policy_review_overdue'));
        $this->assertContains($ids['nonconformity'], $this->gapIds($results, 'nonconformity_closed_without_action'));
        $this->assertContains($ids['control'], $this->gapIds($results, 'control_without_evidence'));

        foreach ($results['risk_without_owner']['gaps'] as $gap) {
            if ((int) $gap['id'] === $ids['risk']) {
                $this->assertSame(Risk::class, $gap['itemtype']);
                $this->assertStringContainsString('id=' . $ids['risk'], $gap['url'], 'lien vers la fiche à corriger');
                $this->assertNotSame('', $gap['detail']);
            }
        }

        // Un test récent suffit à prouver la mesure.
        global $DB;
        $DB->insert('glpi_plugin_grcmanager_controltests', [
            'plugin_grcmanager_controls_id' => $ids['control'], 'test_date' => date('Y-m-d'), 'result' => 'effective',
        ]);
        $this->assertNotContains($ids['control'], $this->gapIds(ConsistencyCheck::run(), 'control_without_evidence'));
    }

    public function testChecksAreReadOnly(): void
    {
        global $DB;

        $ids = $this->seedInconsistencies();
        $before = $DB->request(['FROM' => Risk::getTable(), 'WHERE' => ['id' => $ids['risk']]])->current();
        ConsistencyCheck::report(ConsistencyRules::filters([]));
        $after = $DB->request(['FROM' => Risk::getTable(), 'WHERE' => ['id' => $ids['risk']]])->current();

        $this->assertSame($before, $after);
    }

    public function testRulesOutsideTheRightsAreNeverEvaluated(): void
    {
        $ids = $this->seedInconsistencies();
        $sensitive = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        try {
            $results = ConsistencyCheck::run();
            foreach (['risk_without_owner', 'risk_review_overdue', 'risk_accepted_without_approval'] as $rule) {
                $this->assertFalse($results[$rule]['evaluated'], $rule);
                $this->assertSame([], $results[$rule]['gaps'], $rule);
            }
            $this->assertFalse($results['control_applicable_without_risk']['evaluated'], 'liens vers les risques');
            $this->assertTrue($results['policy_review_overdue']['evaluated']);
            $summary = ConsistencyCheck::summarize($results);
            $this->assertGreaterThan(0, $summary['not_evaluated']);

            $report = ConsistencyCheck::report(ConsistencyRules::filters([]), $results);
            $pdf = ConsistencyCheck::renderPdf($report);
            $this->assertStringNotContainsString('Risque fictif sans propriétaire 614', $pdf);
            $this->assertStringContainsString('Politique fictive 614', $pdf);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $sensitive;
        }
        $this->assertContains($ids['risk'], $this->gapIds(ConsistencyCheck::run(), 'risk_without_owner'));
    }

    public function testItemsOfAnotherEntityAreNeverListed(): void
    {
        global $DB;

        $entityA = $this->createTestEntity(0, 'Entité fictive A 614');
        $entityB = $this->createTestEntity(0, 'Entité fictive B 614');
        $processes = [];
        foreach (['A' => $entityA, 'B' => $entityB] as $suffix => $entity) {
            $DB->insert(BusinessProcess::getTable(), [
                'name' => 'Processus fictif sans RTO ' . $suffix, 'entities_id' => $entity, 'rto_hours' => 0,
            ]);
            $processes[$suffix] = (int) $DB->insertId();
        }
        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        try {
            $gaps = $this->gapIds(ConsistencyCheck::run(), 'business_process_without_rto');
            $this->assertContains($processes['A'], $gaps);
            $this->assertNotContains($processes['B'], $gaps, 'processus d\'une entité non active');
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
        }
    }

    public function testRulesCanBeDisabledAndThresholdsChanged(): void
    {
        $ids = $this->seedInconsistencies();
        $this->assertTrue(ConsistencyCheck::saveSettings([
            'risk_without_owner'     => ['enabled' => '0'],
            'policy_review_overdue'  => ['enabled' => '1', 'threshold' => '60'],
            'risk_without_decision'  => ['enabled' => '1', 'threshold' => 'critical'],
        ] + array_map(static fn (): array => ['enabled' => '1'], ConsistencyRules::RULES)));
        try {
            $settings = ConsistencyCheck::getSettings();
            $this->assertFalse($settings['risk_without_owner']['enabled']);
            $this->assertSame(60, $settings['policy_review_overdue']['threshold']);

            $results = ConsistencyCheck::run();
            $this->assertFalse($results['risk_without_owner']['evaluated']);
            $this->assertSame(1, ConsistencyCheck::summarize($results)['disabled']);
            $policyGaps = $this->gapIds($results, 'policy_review_overdue');
            $this->assertNotContains($ids['policy'], $policyGaps, 'dans la tolérance');
            $this->assertContains($ids['risk'], $this->gapIds($results, 'risk_without_decision'), 'risque critique');
        } finally {
            Config::deleteConfigurationValues(AuditTeam::CONFIG_CONTEXT, [ConsistencyCheck::CONFIG_KEY]);
        }
    }

    public function testReportFiltersSnapshotAndDashboardCard(): void
    {
        global $DB;

        $ids = $this->seedInconsistencies();
        $report = ConsistencyCheck::report(['area' => 'policies', 'severity' => '', 'rule' => '']);
        $this->assertSame(['policies'], array_column($report['areas'], 'key'));
        $policyGaps = array_column($report['areas'][0]['rules'][0]['gaps'], 'id');
        $this->assertContains($ids['policy'], $policyGaps);

        $results = ConsistencyCheck::run();
        $summary = ConsistencyCheck::summarize($results);
        $this->assertGreaterThan(0, $summary['gaps']);
        $this->assertNotNull($summary['score']);

        $DB->delete(ConsistencyCheck::SNAPSHOTS_TABLE, ['users_id' => 2]);
        ConsistencyCheck::snapshot($summary);
        ConsistencyCheck::snapshot($summary);
        $this->assertSame(1, countElementsInTable(ConsistencyCheck::SNAPSHOTS_TABLE, [
            'users_id' => 2, 'month' => date('Y-m'),
        ]));
        $history = ConsistencyCheck::history();
        $expected = ['month' => date('Y-m'), 'score' => $summary['score'], 'gaps' => $summary['gaps']];
        $this->assertSame($expected, end($history));

        $card = SecuredDashboardCards::ismsConsistencyGaps();
        $this->assertSame($summary['gaps'], $card['number']);
        $this->assertStringContainsString('consistencycheck.php', $card['url']);
        $this->assertTrue(SecuredDashboardCards::canShow('ismsConsistencyGaps'));
    }
}

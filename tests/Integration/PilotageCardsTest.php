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

use GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #143 : cartes de pilotage du tableau de bord GRC.
 */
final class PilotageCardsTest extends GrcmanagerIntegrationTestCase
{
    public function testEveryCardReturnsAUsableValue(): void
    {
        $framework = SecuredDashboardCards::complianceByFramework();
        $this->assertSame(['ISO 27001', 'NIS2', 'ANSSI'], array_column($framework['data'], 'label'));
        foreach ($framework['data'] as $entry) {
            $this->assertGreaterThanOrEqual(0, $entry['number']);
            $this->assertLessThanOrEqual(100, $entry['number']);
        }
        foreach (
            ['upcomingComplianceDeadlines', 'accessReviewProgress', 'securityIncidentRegulatoryDeadlines',
                'supplierQuestionnaireAverage', 'periodicReviewsOverdue', 'criticalSuppliersWithoutAssessment'] as $card
        ) {
            $result = SecuredDashboardCards::$card();
            $this->assertIsInt($result['number'], $card);
            $this->assertNotSame('', $result['label'], $card);
        }
    }

    public function testTrendKeepsTheLastTwelveRecordedMonthsInOrder(): void
    {
        // 13 mois avec indicateurs (2025-01 à 2026-01), un mois plus récent sans indicateurs.
        $byMonth = ['2026-02' => null];
        for ($i = 0; $i < 13; $i++) {
            $month = date('Y-m', mktime(0, 0, 0, 1 + $i, 1, 2025));
            $byMonth[$month] = ['open_risks' => $i, 'open_nonconformities' => 20 - $i];
        }
        $trend = PilotageCards::trendSeries($byMonth);

        $this->assertSame('2025-02', $trend['labels'][0], 'Les 12 derniers mois enregistrés seulement.');
        $this->assertSame('2026-01', end($trend['labels']), 'Le mois sans indicateurs est ignoré.');
        $this->assertSame(range(1, 12), $trend['series'][0]['data']);
        $this->assertSame(range(19, 8), $trend['series'][1]['data']);
    }

    public function testMonthlyTrendShowsTheCurrentMonthAndSnapshotStoresIndicators(): void
    {
        global $DB;

        $trend = SecuredDashboardCards::monthlyTrend()['data'];
        $this->assertSame(date('Y-m'), end($trend['labels']));
        $this->assertSame(
            PilotageCards::currentIndicators()['open_risks'],
            end($trend['series'][0]['data'])
        );

        \PluginGrcmanagerMaturity::snapshot();
        $row = $DB->request([
            'FROM'  => \PluginGrcmanagerMaturity::SNAPSHOTS_TABLE,
            'WHERE' => ['month' => date('Y-m')],
        ])->current();
        $this->assertSame(PilotageCards::currentIndicators(), json_decode((string) $row['indicators'], true));
    }

    public function testCriticalSupplierWithoutQuestionnaireIsCounted(): void
    {
        $supplier = new \Supplier();
        $supplierId = (int) $supplier->add(['name' => 'PHPUnit fournisseur critique', 'entities_id' => 0]);
        $before = SecuredDashboardCards::criticalSuppliersWithoutAssessment()['number'];
        $risk = new \PluginGrcmanagerSupplierRisk();
        $riskId = (int) $risk->add([
            'suppliers_id' => $supplierId,
            'title'        => 'PHPUnit dépendance critique',
            'probability'  => 'certain',
            'impact'       => 'critical',
            'status'       => 'identified',
        ]);
        try {
            $risk->getFromDB($riskId);
            $this->assertContains($risk->fields['risk_level'], ['high', 'critical']);
            $this->assertSame($before + 1, SecuredDashboardCards::criticalSuppliersWithoutAssessment()['number']);
            // Un questionnaire jamais rempli (score 0 par défaut) ne compte pas dans la moyenne.
            $average = SecuredDashboardCards::supplierQuestionnaireAverage()['number'];
            $risk->update(['id' => $riskId, 'questionnaire_score' => 0]);
            $this->assertSame($average, SecuredDashboardCards::supplierQuestionnaireAverage()['number']);

            global $DB;
            $DB->update(
                \PluginGrcmanagerSupplierRisk::getTable(),
                ['questionnaire_score' => 90, 'questionnaire_date' => date('Y-m-d H:i:s')],
                ['id' => $riskId]
            );
            $this->assertSame($before, SecuredDashboardCards::criticalSuppliersWithoutAssessment()['number']);
        } finally {
            $risk->delete(['id' => $riskId], true);
            $supplier->delete(['id' => $supplierId], true);
        }
    }

    public function testDirectionDashboardIsSeededWithKnownCards(): void
    {
        global $DB;

        $dashboard = new \Glpi\Dashboard\Dashboard(DefaultDashboardService::DIRECTION_KEY);
        $this->assertTrue($dashboard->canViewCurrent());
        $cards = (new \Glpi\Dashboard\Grid())->getAllDasboardCards(true);
        $items = iterator_to_array($DB->request([
            'FROM'  => \Glpi\Dashboard\Item::getTable(),
            'WHERE' => ['dashboards_dashboards_id' => $dashboard->fields['id']],
        ]), false);
        $this->assertCount(11, $items);
        foreach ($items as $item) {
            $this->assertArrayHasKey($item['card_id'], $cards, $item['card_id']);
        }
    }

    public function testOverdueNis2NotificationIsCounted(): void
    {
        $before = SecuredDashboardCards::securityIncidentRegulatoryDeadlines()['number'];
        (new \PluginGrcmanagerSecurityIncident())->add([
            'name'            => 'PHPUnit incident NIS2 en retard',
            'entities_id'     => 0,
            'content'         => 'Rançongiciel.',
            'date'            => date('Y-m-d H:i:s', strtotime('-5 days')),
            'nis2_applicable' => 1,
        ]);
        // Alerte précoce (24 h) et notification (72 h) dépassées.
        $this->assertSame($before + 2, SecuredDashboardCards::securityIncidentRegulatoryDeadlines()['number']);
    }
}

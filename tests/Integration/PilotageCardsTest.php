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
                'supplierQuestionnaireAverage', 'periodicReviewsOverdue'] as $card
        ) {
            $result = SecuredDashboardCards::$card();
            $this->assertIsInt($result['number'], $card);
            $this->assertNotSame('', $result['label'], $card);
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

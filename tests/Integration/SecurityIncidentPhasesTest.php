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
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #370 : phases de réponse NIST saisies depuis le formulaire, délais au tableau de bord.
 */
final class SecurityIncidentPhasesTest extends GrcmanagerIntegrationTestCase
{
    public function testPhaseDatesAreSavedFromTheFormAndClearedWhenEmptied(): void
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add(['name' => 'PHPUnit phases', 'date' => '2031-05-01 08:00:00', 'entities_id' => 0]);

        $this->assertTrue($incident->update([
            'id'              => $id,
            'acknowledged_at' => '2031-05-01T09:00',
            'contained_at'    => '2031-05-01T20:00',
            'eradicated_at'   => 'pas une date',
        ]));
        $incident->getFromDB($id);
        $this->assertSame('2031-05-01 09:00:00', $incident->fields['acknowledged_at']);
        $this->assertSame('2031-05-01 20:00:00', $incident->fields['contained_at']);
        $this->assertNull($incident->fields['eradicated_at']);

        $incident->update(['id' => $id, 'contained_at' => '']);
        $incident->getFromDB($id);
        $this->assertNull($incident->fields['contained_at']);
    }

    public function testAverageTimeToContainCard(): void
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->add([
            'name'         => 'PHPUnit confinement',
            'date'         => '2031-06-01 00:00:00',
            'contained_at' => '2031-06-01T10:00',
            'entities_id'  => 0,
        ]);

        $card = SecuredDashboardCards::averageTimeToContain();
        $this->assertGreaterThan(0, $card['number']);
        $this->assertArrayHasKey('label', SecuredDashboardCards::averageTimeToClose());
    }

    public function testPhaseSearchOptionsExist(): void
    {
        $fields = array_column(
            array_filter((new PluginGrcmanagerSecurityIncident())->rawSearchOptions(), 'is_array'),
            'field',
            'id'
        );
        foreach (['acknowledged_at', 'contained_at', 'eradicated_at', 'recovered_at'] as $field) {
            $this->assertContains($field, $fields);
        }
    }
}

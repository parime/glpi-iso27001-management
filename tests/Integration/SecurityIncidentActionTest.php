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

use GlpiPlugin\Grcmanager\SecurityIncidentAction;
use GlpiPlugin\Grcmanager\Services\Incident\ActionLog;
use Log;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #370 : journal des actions d'investigation, PDF consigné dans l'historique de l'incident.
 */
final class SecurityIncidentActionTest extends GrcmanagerIntegrationTestCase
{
    public function testAddWithDefaultsAndValidation(): void
    {
        $incidentId = $this->createIncident('PHPUnit journal');

        $action = new SecurityIncidentAction();
        $id = (int) $action->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'date'    => '2031-07-01T10:30',
            'target'  => 'PC-COMPTA-03',
            'tool'    => 'not-a-tool',
            'result'  => 'success',
            'command' => 'Isolate host',
        ]);
        $this->assertGreaterThan(0, $id);
        $action->getFromDB($id);
        $this->assertSame('2031-07-01 10:30:00', $action->fields['date']);
        $this->assertSame('other', $action->fields['tool']);
        $this->assertSame((int) $_SESSION['glpiID'], (int) $action->fields['users_id']);

        // Cible obligatoire, date lisible obligatoire.
        $this->assertFalse((new SecurityIncidentAction())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'target' => '  ',
        ]));
        $this->assertFalse((new SecurityIncidentAction())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'target' => 'SRV-AD01',
            'date'   => 'pas une date',
        ]));

        $this->assertCount(1, SecurityIncidentAction::getForIncident($incidentId));
    }

    public function testPdfIsRecordedInTheIncidentHistoryWithTheFingerprint(): void
    {
        $incidentId = $this->createIncident('PHPUnit journal PDF');
        (new SecurityIncidentAction())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'target' => 'FW-01',
            'tool'   => 'firewall',
            'result' => 'to_check',
        ]);
        $incident = new PluginGrcmanagerSecurityIncident();
        $incident->getFromDB($incidentId);

        $pdf = SecurityIncidentAction::renderPdf($incident);
        $this->assertStringStartsWith('%PDF-', $pdf);

        $hash = ActionLog::contentHash(SecurityIncidentAction::getForIncident($incidentId));
        $this->assertSame(1, countElementsInTable(Log::getTable(), [
            'itemtype' => PluginGrcmanagerSecurityIncident::class,
            'items_id' => $incidentId,
            'new_value' => ['LIKE', '%' . $hash . '%'],
        ]));
    }

    private function createIncident(string $name): int
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return (int) $incident->add(['name' => $name, 'entities_id' => 0]);
    }
}

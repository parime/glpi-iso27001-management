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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\SecurityIncidentIoc;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #371 : journal d'audit chaîné des incidents de sécurité.
 */
final class AuditLogTest extends GrcmanagerIntegrationTestCase
{
    public function testIncidentEventsAreChainedAndTamperingIsDetected(): void
    {
        global $DB;

        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add(['name' => 'PHPUnit audit', 'entities_id' => 0]);
        $incident->update(['id' => $incidentId, 'severity' => 'major']);
        SecurityIncidentIoc::addFromText($incidentId, '198.51.100.77');

        $actions = array_column(iterator_to_array($DB->request([
            'SELECT' => ['action'],
            'FROM'   => AuditLog::getTable(),
            'WHERE'  => ['plugin_grcmanager_securityincidents_id' => $incidentId],
            'ORDER'  => 'id ASC',
        ]), false), 'action');
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $this->assertContains('add_link', $actions);

        $this->assertTrue(AuditLog::verifyAll()['valid']);

        // Falsification directe en base : la vérification la détecte à cette entrée.
        $row = $DB->request([
            'FROM'  => AuditLog::getTable(),
            'WHERE' => ['plugin_grcmanager_securityincidents_id' => $incidentId, 'action' => 'update'],
            'LIMIT' => 1,
        ])->current();
        $DB->update(AuditLog::getTable(), ['users_id' => 999999], ['id' => $row['id']]);
        $result = AuditLog::verifyAll();
        $this->assertFalse($result['valid']);
        $this->assertSame((int) $row['id'], $result['broken_id']);

        // Remise en état pour les autres tests.
        $DB->update(AuditLog::getTable(), ['users_id' => $row['users_id']], ['id' => $row['id']]);
        $this->assertTrue(AuditLog::verifyAll()['valid']);
    }
}

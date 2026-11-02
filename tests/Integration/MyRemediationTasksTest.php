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

use GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #370 : cartes « Mes tâches de remédiation » du tableau de bord.
 */
final class MyRemediationTasksTest extends GrcmanagerIntegrationTestCase
{
    public function testOpenTasksAssignedToMeAreListed(): void
    {
        $before = SecuredDashboardCards::myRemediationTasksCount()['number'];

        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add(['name' => 'PHPUnit mes tâches', 'entities_id' => 0]);
        $me = (int) $_SESSION['glpiID'];
        $task = new SecurityIncidentPlaybookTask();
        $mine = (int) $task->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'name' => 'PHPUnit tâche assignée', 'phase' => 'containment', 'users_id' => $me,
        ]);
        (new SecurityIncidentPlaybookTask())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'name' => 'PHPUnit tâche de personne', 'phase' => 'containment',
        ]);

        $this->assertSame($before + 1, SecuredDashboardCards::myRemediationTasksCount()['number']);
        $labels = array_column(SecuredDashboardCards::myRemediationTasks()['data'], 'label');
        $this->assertContains('PHPUnit tâche assignée', $labels);
        $this->assertNotContains('PHPUnit tâche de personne', $labels);

        // Terminée : elle sort de la liste.
        $task->update(['id' => $mine, 'status' => 'done']);
        $this->assertSame($before, SecuredDashboardCards::myRemediationTasksCount()['number']);
    }
}

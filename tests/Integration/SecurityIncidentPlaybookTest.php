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

use GlpiPlugin\Grcmanager\Playbook;
use GlpiPlugin\Grcmanager\PlaybookStep;
use GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #370 : procédures de remédiation fournies, plan de tâches et clôture bloquée.
 */
final class SecurityIncidentPlaybookTest extends GrcmanagerIntegrationTestCase
{
    public function testFiveDefaultPlaybooksAreInstalled(): void
    {
        $this->assertGreaterThanOrEqual(5, countElementsInTable(Playbook::getTable()));
        // Bibliothèque déjà remplie : pas de nouvelle copie.
        $this->assertSame(0, Playbook::seedDefaults());
    }

    public function testApplyCopiesTheStepsAndMandatoryStepsBlockClosure(): void
    {
        $playbook = new Playbook();
        $playbookId = (int) $playbook->add(['name' => 'PHPUnit procédure', 'category' => 'malware', 'is_active' => 1]);
        (new PlaybookStep())->add([
            'plugin_grcmanager_playbooks_id' => $playbookId, 'rank' => 10, 'phase' => 'containment',
            'name' => 'Isoler le poste', 'is_mandatory' => 1,
        ]);
        (new PlaybookStep())->add([
            'plugin_grcmanager_playbooks_id' => $playbookId, 'rank' => 20, 'phase' => 'recovery',
            'name' => 'Remettre en service', 'is_mandatory' => 0,
        ]);

        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add([
            'name' => 'PHPUnit plan de remédiation', 'category' => 'malware', 'entities_id' => 0,
            'root_cause' => 'Pièce jointe piégée', 'lessons_learned' => 'Filtrer les macros',
        ]);
        $incident->getFromDB($incidentId);
        $suggested = Playbook::suggestFor($incident);
        $this->assertTrue($suggested[0]['recommended']);

        $this->assertSame(2, SecurityIncidentPlaybookTask::applyPlaybook($incidentId, $playbookId));
        // Déjà appliquée : pas de tâches en double.
        $this->assertSame(0, SecurityIncidentPlaybookTask::applyPlaybook($incidentId, $playbookId));

        // PDF du plan, consigné dans l'historique de l'incident.
        $this->assertStringStartsWith('%PDF-', SecurityIncidentPlaybookTask::renderPdf($incident));
        $this->assertSame(1, countElementsInTable(\Log::getTable(), [
            'itemtype'  => PluginGrcmanagerSecurityIncident::class,
            'items_id'  => $incidentId,
            'new_value' => ['LIKE', '%SHA-256%'],
        ]));
        $tasks = SecurityIncidentPlaybookTask::getForIncident($incidentId);
        $this->assertSame(['Isoler le poste', 'Remettre en service'], array_column($tasks, 'name'));
        $this->assertTrue(SecurityIncidentPlaybookTask::blocksClosure($incidentId));

        // Clôture refusée tant que l'étape obligatoire est ouverte.
        $close = ['id' => $incidentId, 'status' => PluginGrcmanagerSecurityIncident::CLOSED];
        $this->assertFalse($incident->update($close));

        // Réalisation signée, puis clôture acceptée.
        $task = new SecurityIncidentPlaybookTask();
        $task->getFromDB((int) $tasks[0]['id']);
        $done = ['id' => $task->getID(), 'status' => 'done', 'report' => 'Isolé depuis la console EDR'];
        $this->assertTrue($task->update($done));
        $task->getFromDB($task->getID());
        $this->assertSame((int) $_SESSION['glpiID'], (int) $task->fields['users_id_done']);
        $this->assertNotNull($task->fields['date_done']);
        $this->assertFalse(SecurityIncidentPlaybookTask::blocksClosure($incidentId));
        $this->assertTrue($incident->update($close));

        // Rouverte : la signature est effacée.
        $task->update(['id' => $task->getID(), 'status' => 'todo']);
        $task->getFromDB($task->getID());
        $this->assertSame(0, (int) $task->fields['users_id_done']);
        $this->assertNull($task->fields['date_done']);
    }
}

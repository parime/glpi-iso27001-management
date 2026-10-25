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

use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
use GlpiPlugin\Grcmanager\Services\Exercise\CrisisExercise;
use PluginGrcmanagerCrisisExercise as Exercise;
use PluginGrcmanagerSecurityIncident as Incident;
use PluginGrcmanagerSecurityIncidentTask as Task;

/**
 * Issue #168 : exercice de crise, du lancement au rapport archivé.
 */
final class CrisisExerciseTest extends GrcmanagerIntegrationTestCase
{
    public function testExerciseRunsFromLaunchToArchivedReport(): void
    {
        global $DB;

        $openBefore = SecurityIncidentCardProvider::open()['number'];
        $exerciseId = Exercise::launch('ransomware');
        $this->assertGreaterThan(0, $exerciseId);
        $exercise = new Exercise();
        $exercise->getFromDB($exerciseId);
        $incident = $exercise->getIncident();
        $this->assertNotNull($incident);
        $this->assertSame(1, (int) $incident->fields['is_exercise']);
        $this->assertStringContainsString('[EXERCICE]', $incident->fields['name']);
        $this->assertSame('malware', $incident->fields['category']);
        $this->assertSame(
            $openBefore,
            SecurityIncidentCardProvider::open()['number'],
            'Un incident d\'exercice ne compte pas dans les incidents ouverts.'
        );

        $stepCount = count(CrisisExercise::SCENARIOS['ransomware']['steps']);
        $this->assertSame($stepCount, countElementsInTable(Task::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incident->getID(),
        ]));
        $steps = $exercise->collect()['steps'];
        $this->assertCount($stepCount, $steps);
        $this->assertSame(CrisisExercise::STEP_PENDING, $steps[0]['status']);

        // Étape cochée dans la salle de crise : heure retenue, tâche passée à « Fait ».
        $this->assertTrue($exercise->markStepDone($steps[0]['id']));
        $this->assertFalse($exercise->markStepDone($steps[0]['id']), 'Une étape ne se coche qu\'une fois.');
        $first = $DB->request(['FROM' => Exercise::STEPS_TABLE, 'WHERE' => ['id' => $steps[0]['id']]])->current();
        $task = new Task();
        $task->getFromDB((int) $first['plugin_grcmanager_securityincidenttasks_id']);
        $this->assertSame(\Planning::DONE, (int) $task->fields['state']);

        // Tâche passée à « Fait » depuis l'incident : l'étape suit.
        $second = $DB->request(['FROM' => Exercise::STEPS_TABLE, 'WHERE' => ['id' => $steps[1]['id']]])->current();
        $task->getFromDB((int) $second['plugin_grcmanager_securityincidenttasks_id']);
        $task->update(['id' => $task->getID(), 'state' => \Planning::DONE]);
        $summary = $exercise->collect()['summary'];
        $this->assertSame(2, $summary['done']);
        $this->assertSame(2, $summary['on_time']);

        $documentsId = $exercise->finish();
        try {
            $this->assertGreaterThan(0, $documentsId);
            $exercise->getFromDB($exerciseId);
            $this->assertNotEmpty($exercise->fields['ended_at']);
            $this->assertSame($documentsId, (int) $exercise->fields['documents_id']);
            $data = $exercise->collect();
            $this->assertSame(CrisisExercise::STEP_MISSED, $data['steps'][$stepCount - 1]['status']);
            $this->assertFalse($exercise->markStepDone($data['steps'][$stepCount - 1]['id']), 'Exercice terminé.');
            $this->assertSame(1, countElementsInTable('glpi_documents_items', [
                'documents_id' => $documentsId,
                'itemtype'     => Incident::class,
                'items_id'     => $incident->getID(),
            ]));
            $this->assertSame(2, countElementsInTable('glpi_documents_items', [
                'documents_id' => $documentsId,
                'itemtype'     => \PluginGrcmanagerControl::class,
            ]), 'Preuve sur A.5.29 et A.5.30.');
            $document = new \Document();
            $document->getFromDB($documentsId);
            $this->assertSame('application/pdf', $document->fields['mime']);
            $this->assertContains($exerciseId, array_column(Exercise::history(), 'id'));
        } finally {
            (new \Document())->delete(['id' => $documentsId], true);
        }
    }

    public function testUnknownScenarioIsRefused(): void
    {
        $this->assertSame(0, Exercise::launch('inconnu'));
    }
}

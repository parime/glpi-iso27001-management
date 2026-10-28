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

/**
 * Issue #139 : note des participants, résultat calculé depuis le seuil, rattrapage.
 */
final class TrainingScoreTest extends GrcmanagerIntegrationTestCase
{
    public function testScoresComputeResultsAndRetakeList(): void
    {
        global $DB;

        $users = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0], 'ORDER' => 'id', 'LIMIT' => 3,
        ]), false), 'id'));
        $this->assertCount(3, $users);

        $training = new \GlpiPlugin\Grcmanager\Training();
        $id = (int) $training->add([
            'title'          => 'PHPUnit quiz hameçonnage',
            'max_score'      => 40,
            'pass_threshold' => 80,
            'participants'   => $users,
        ]);
        try {
            $this->assertTrue($training->update([
                'id'                 => $id,
                'participants'       => $users,
                'participant_status' => array_fill_keys($users, 'completed'),
                'participant_score'  => [$users[0] => '32', $users[1] => '31,5', $users[2] => ''],
                // Le choix manuel est ignoré pour une formation notée.
                'participant_result' => array_fill_keys($users, 'passed'),
            ]));

            $byUser = array_column(\GlpiPlugin\Grcmanager\Training::getParticipants($id), null, 'users_id');
            $this->assertSame('passed', $byUser[$users[0]]['assessment_result'], '32/40 = 80 %.');
            $this->assertSame(32.0, $byUser[$users[0]]['score']);
            $this->assertSame('failed', $byUser[$users[1]]['assessment_result']);
            $this->assertSame(31.5, $byUser[$users[1]]['score']);
            $this->assertSame('not_applicable', $byUser[$users[2]]['assessment_result'], 'Pas encore noté.');
            $this->assertNull($byUser[$users[2]]['score']);

            $this->assertSame([$users[1]], \GlpiPlugin\Grcmanager\Training::getRetakeParticipantIds($id));

            // Issue #139, phase 2 : rapport PDF de la session.
            $training->getFromDB($id);
            $report = \GlpiPlugin\Grcmanager\Training::renderSessionReport($training);
            $this->assertStringContainsString('32/40 (80 %)', $report);
            $this->assertStringContainsString(__('To retake', 'grcmanager'), $report);
            $this->assertStringStartsWith('%PDF', \GlpiPlugin\Grcmanager\Pdf\PdfRenderer::renderHtmlToPdf($report));

            // Une note hors barème est ignorée, jamais enregistrée.
            $training->update([
                'id'                 => $id,
                'participants'       => $users,
                'participant_status' => array_fill_keys($users, 'completed'),
                'participant_score'  => [$users[0] => '45'],
            ]);
            $byUser = array_column(\GlpiPlugin\Grcmanager\Training::getParticipants($id), null, 'users_id');
            $this->assertNull($byUser[$users[0]]['score']);
            $this->assertSame('not_applicable', $byUser[$users[0]]['assessment_result']);
        } finally {
            $training->delete(['id' => $id], true);
            $DB->delete('glpi_plugin_grcmanager_trainings_users', ['plugin_grcmanager_trainings_id' => $id]);
        }
    }

    public function testRetakeFormIsPrefilledButNothingIsSaved(): void
    {
        global $DB;

        $users = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_deleted' => 0], 'ORDER' => 'id', 'LIMIT' => 2,
        ]), false), 'id'));
        $training = new \GlpiPlugin\Grcmanager\Training();
        $id = (int) $training->add(['title' => 'PHPUnit session source', 'max_score' => 20, 'pass_threshold' => 50]);
        $DB->insert('glpi_plugin_grcmanager_trainings_users', [
            'plugin_grcmanager_trainings_id' => $id,
            'users_id'                       => $users[0],
            'completion_status'              => 'completed',
            'assessment_result'              => 'failed',
            'score'                          => 5,
        ]);
        $count = countElementsInTable($training->getTable());
        try {
            ob_start();
            (new \GlpiPlugin\Grcmanager\Training())->showForm(0, ['retake_of' => $id]);
            $html = (string) ob_get_clean();

            // La CI tourne en anglais : comparer au titre traduit.
            $this->assertStringContainsString(
                htmlspecialchars(sprintf(__('%s - retake', 'grcmanager'), 'PHPUnit session source')),
                $html
            );
            $this->assertStringContainsString('name="max_score"', $html);
            $this->assertSame($count, countElementsInTable($training->getTable()), 'Rien n\'est enregistré.');
        } finally {
            $training->delete(['id' => $id], true);
            $DB->delete('glpi_plugin_grcmanager_trainings_users', ['plugin_grcmanager_trainings_id' => $id]);
        }
    }
}

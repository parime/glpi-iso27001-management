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

use PluginGrcmanagerTraining;

/**
 * Issue #139 (phase 2) : plusieurs sessions par formation, taux de réussite de l'ensemble.
 */
final class TrainingSessionTest extends GrcmanagerIntegrationTestCase
{
    private function training(array $input): int
    {
        $id = (int) (new PluginGrcmanagerTraining())->add($input + ['title' => 'Sensibilisation hameçonnage']);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @param array<int, string> $results users_id => passed|failed
     */
    private function results(int $trainingId, array $results): void
    {
        global $DB;

        foreach ($results as $usersId => $result) {
            $DB->insert('glpi_plugin_grcmanager_trainings_users', [
                'plugin_grcmanager_trainings_id' => $trainingId,
                'users_id'                       => $usersId,
                'completion_status'              => 'completed',
                'assessment_result'              => $result,
            ]);
        }
    }

    public function testSessionsAttachToTheMainTrainingOnly(): void
    {
        $main = $this->training([]);
        $session = $this->training(['plugin_grcmanager_trainings_id' => $main]);
        // Rattachée à une session : remonte à la formation principale.
        $other = $this->training(['plugin_grcmanager_trainings_id' => $session]);

        $training = new PluginGrcmanagerTraining();
        $this->assertTrue($training->getFromDB($other));
        $this->assertSame($main, (int) $training->fields['plugin_grcmanager_trainings_id']);

        // Jamais à elle-même, et une formation qui a des sessions reste principale.
        $this->assertSame(0, PluginGrcmanagerTraining::mainTrainingId($main, $main));
        $alone = $this->training([]);
        $this->assertSame(0, PluginGrcmanagerTraining::mainTrainingId($alone, $main));
        $this->assertSame($main, PluginGrcmanagerTraining::mainTrainingId($main, $alone));

        $this->assertSame([$session, $other], array_column(PluginGrcmanagerTraining::getSessions($main), 'id'));
        $this->assertArrayNotHasKey($main, PluginGrcmanagerTraining::getMainTrainings($main));
        $this->assertArrayNotHasKey($session, PluginGrcmanagerTraining::getMainTrainings());
    }

    public function testProgramRateCoversTheMainTrainingAndEverySession(): void
    {
        $users = [
            $this->createTestUser('Michel', 'Un'),
            $this->createTestUser('Nadia', 'Deux'),
            $this->createTestUser('Omar', 'Trois'),
            $this->createTestUser('Paula', 'Quatre'),
        ];
        $main = $this->training([]);
        $session = $this->training(['plugin_grcmanager_trainings_id' => $main]);
        $this->results($main, [$users[0] => 'passed', $users[1] => 'failed']);
        $this->results($session, [$users[1] => 'passed', $users[2] => 'passed', $users[3] => 'failed']);

        $this->assertSame(
            ['passed' => 3, 'assessed' => 5, 'rate' => 60],
            PluginGrcmanagerTraining::getProgramRate($main)
        );
        $sessions = PluginGrcmanagerTraining::getSessions($main);
        $this->assertSame(3, $sessions[0]['participants']);
        $this->assertSame(67, $sessions[0]['rate']['rate']);
    }

    public function testPurgingTheMainTrainingFreesItsSessions(): void
    {
        $main = $this->training([]);
        $session = $this->training(['plugin_grcmanager_trainings_id' => $main]);

        (new PluginGrcmanagerTraining())->delete(['id' => $main], true);

        $training = new PluginGrcmanagerTraining();
        $this->assertTrue($training->getFromDB($session));
        $this->assertSame(0, (int) $training->fields['plugin_grcmanager_trainings_id']);
    }

    public function testNewSessionFormIsPrefilledButNothingIsSaved(): void
    {
        $main = $this->training(['max_score' => 40, 'pass_threshold' => 80]);
        $count = countElementsInTable(PluginGrcmanagerTraining::getTable());

        ob_start();
        (new PluginGrcmanagerTraining())->showForm(0, ['session_of' => $main]);
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/name="max_score"[^>]*value="40"|value="40"[^>]*name="max_score"/',
            $html
        );
        $this->assertStringContainsString('name="users_id_trainer"', $html);
        $saved = countElementsInTable(PluginGrcmanagerTraining::getTable());
        $this->assertSame($count, $saved, 'Rien n\'est enregistré.');
    }

    public function testSessionsTabListsTheSessionsOfAMainTrainingOnly(): void
    {
        $main = $this->training([]);
        $session = $this->training(['plugin_grcmanager_trainings_id' => $main, 'location' => 'Salle Turing']);
        $tab = new \PluginGrcmanagerTrainingSession();

        $mainItem = new PluginGrcmanagerTraining();
        $this->assertTrue($mainItem->getFromDB($main));
        $this->assertNotSame('', $tab->getTabNameForItem($mainItem));
        ob_start();
        \PluginGrcmanagerTrainingSession::displayTabContentForItem($mainItem);
        $this->assertStringContainsString('Salle Turing', (string) ob_get_clean());

        $sessionItem = new PluginGrcmanagerTraining();
        $this->assertTrue($sessionItem->getFromDB($session));
        $this->assertSame('', $tab->getTabNameForItem($sessionItem));
    }
}

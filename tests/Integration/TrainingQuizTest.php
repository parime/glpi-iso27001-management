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

use PluginGrcmanagerAuditTeam;
use PluginGrcmanagerRestrictedDocument;
use PluginGrcmanagerTraining;
use PluginGrcmanagerTrainingQuiz;

/**
 * Issue #139 (phase 2) : copie de quiz de chaque participant, lisible par le participant, le
 * formateur et le groupe RSSI seulement.
 */
final class TrainingQuizTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Indépendant du groupe RSSI de l'instance (annulé avec la transaction du test).
        PluginGrcmanagerAuditTeam::setCisoGroupId(0);
    }

    /**
     * @return array{training: int, participation: int, participant: int, trainer: int}
     */
    private function session(): array
    {
        $participant = $this->createTestUser('Michel', 'Participant');
        $trainer = $this->createTestUser('Fanny', 'Formatrice');
        $training = (int) (new PluginGrcmanagerTraining())->add([
            'title'            => 'Quiz hameçonnage',
            'users_id_trainer' => $trainer,
            'participants'     => [$participant],
        ]);
        $participation = PluginGrcmanagerTrainingQuiz::getParticipationId($training, $participant);
        $this->assertGreaterThan(0, $participation);

        return [
            'training'      => $training,
            'participation' => $participation,
            'participant'   => $participant,
            'trainer'       => $trainer,
        ];
    }

    private function attachCopy(int $participation): int
    {
        $prefix = uniqid('', true);
        file_put_contents(GLPI_TMP_DIR . '/' . $prefix . 'copie.txt', "Copie de quiz\n");

        return PluginGrcmanagerTrainingQuiz::attach($participation, $prefix . 'copie.txt', $prefix);
    }

    public function testCopyIsReadableByTheParticipantTheTrainerAndTheCisoGroupOnly(): void
    {
        $s = $this->session();
        $this->assertTrue(PluginGrcmanagerTrainingQuiz::canRead($s['participation'], $s['participant']));
        $this->assertTrue(PluginGrcmanagerTrainingQuiz::canRead($s['participation'], $s['trainer']));
        // Super-Admin, ni participant ni formateur : refusé.
        $this->assertFalse(PluginGrcmanagerTrainingQuiz::canRead($s['participation'], 2));

        $groupsId = (int) (new \Group())->add(['name' => 'RSSI #139', 'entities_id' => 0]);
        (new \Group_User())->add(['groups_id' => $groupsId, 'users_id' => 2]);
        PluginGrcmanagerAuditTeam::setCisoGroupId($groupsId);
        $this->assertTrue(PluginGrcmanagerTrainingQuiz::canRead($s['participation'], 2));
    }

    public function testAttachedCopyIsRestrictedAndReplacesThePreviousOne(): void
    {
        $s = $this->session();
        $first = $this->attachCopy($s['participation']);
        $this->assertGreaterThan(0, $first);
        $this->assertSame(
            ['itemtype' => PluginGrcmanagerTrainingQuiz::class, 'items_id' => $s['participation']],
            PluginGrcmanagerRestrictedDocument::sourceOf($first)
        );
        // La session connectée (Super-Admin) n'est ni participante ni formatrice.
        $this->assertFalse(PluginGrcmanagerRestrictedDocument::canReadSource($first));

        $second = $this->attachCopy($s['participation']);
        $participation = PluginGrcmanagerTrainingQuiz::getParticipation($s['participation']);
        $this->assertSame($second, $participation['documents_id'] ?? 0);
        $this->assertFalse((new \Document())->getFromDB($first), 'La copie remplacée est supprimée.');
    }

    public function testCopyDisappearsWithItsParticipantOrItsSession(): void
    {
        $s = $this->session();
        $documentsId = $this->attachCopy($s['participation']);

        (new PluginGrcmanagerTraining())->update(['id' => $s['training'], 'participants' => []]);
        $this->assertFalse((new \Document())->getFromDB($documentsId));

        $s = $this->session();
        $documentsId = $this->attachCopy($s['participation']);
        (new PluginGrcmanagerTraining())->delete(['id' => $s['training']], true);
        $this->assertFalse((new \Document())->getFromDB($documentsId));
    }

    public function testMyTrainingsTabListsOnlyTheConnectedUsersParticipations(): void
    {
        $s = $this->session();
        $this->attachCopy($s['participation']);
        $saved = $_SESSION['glpiID'];
        try {
            $_SESSION['glpiID'] = $s['participant'];
            ob_start();
            PluginGrcmanagerTrainingQuiz::displayTabContentForItem(new \Preference());
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('Quiz hameçonnage', $html);
            $this->assertStringContainsString('trainingquiz.send.php?id=' . $s['participation'], $html);

            $_SESSION['glpiID'] = $s['trainer'];
            ob_start();
            PluginGrcmanagerTrainingQuiz::displayTabContentForItem(new \Preference());
            $this->assertStringNotContainsString('Quiz hameçonnage', (string) ob_get_clean());
        } finally {
            $_SESSION['glpiID'] = $saved;
        }
    }
}

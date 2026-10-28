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

use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Training;
use GlpiPlugin\Grcmanager\TrainingQuiz;

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
        \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId(0);
    }

    /**
     * @return array{training: int, participation: int, participant: int, trainer: int}
     */
    private function session(): array
    {
        $participant = $this->createTestUser('Michel', 'Participant');
        $trainer = $this->createTestUser('Fanny', 'Formatrice');
        $training = (int) (new \GlpiPlugin\Grcmanager\Training())->add([
            'title'            => 'Quiz hameçonnage',
            'users_id_trainer' => $trainer,
            'participants'     => [$participant],
        ]);
        $participation = \GlpiPlugin\Grcmanager\TrainingQuiz::getParticipationId($training, $participant);
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

        return \GlpiPlugin\Grcmanager\TrainingQuiz::attach($participation, $prefix . 'copie.txt', $prefix);
    }

    public function testCopyIsReadableByTheParticipantTheTrainerAndTheCisoGroupOnly(): void
    {
        $s = $this->session();
        $this->assertTrue(\GlpiPlugin\Grcmanager\TrainingQuiz::canRead($s['participation'], $s['participant']));
        $this->assertTrue(\GlpiPlugin\Grcmanager\TrainingQuiz::canRead($s['participation'], $s['trainer']));
        // Super-Admin, ni participant ni formateur : refusé.
        $this->assertFalse(\GlpiPlugin\Grcmanager\TrainingQuiz::canRead($s['participation'], 2));

        $groupsId = (int) (new \Group())->add(['name' => 'RSSI #139', 'entities_id' => 0]);
        (new \Group_User())->add(['groups_id' => $groupsId, 'users_id' => 2]);
        \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId($groupsId);
        $this->assertTrue(\GlpiPlugin\Grcmanager\TrainingQuiz::canRead($s['participation'], 2));
    }

    public function testAttachedCopyIsRestrictedAndReplacesThePreviousOne(): void
    {
        $s = $this->session();
        $first = $this->attachCopy($s['participation']);
        $this->assertGreaterThan(0, $first);
        $this->assertSame(
            ['itemtype' => \GlpiPlugin\Grcmanager\TrainingQuiz::class, 'items_id' => $s['participation']],
            \GlpiPlugin\Grcmanager\RestrictedDocument::sourceOf($first)
        );
        // La session connectée (Super-Admin) n'est ni participante ni formatrice.
        $this->assertFalse(\GlpiPlugin\Grcmanager\RestrictedDocument::canReadSource($first));

        $second = $this->attachCopy($s['participation']);
        $participation = \GlpiPlugin\Grcmanager\TrainingQuiz::getParticipation($s['participation']);
        $this->assertSame($second, $participation['documents_id'] ?? 0);
        $this->assertFalse((new \Document())->getFromDB($first), 'La copie remplacée est supprimée.');
    }

    public function testCopyDisappearsWithItsParticipantOrItsSession(): void
    {
        $s = $this->session();
        $documentsId = $this->attachCopy($s['participation']);

        (new \GlpiPlugin\Grcmanager\Training())->update(['id' => $s['training'], 'participants' => []]);
        $this->assertFalse((new \Document())->getFromDB($documentsId));

        $s = $this->session();
        $documentsId = $this->attachCopy($s['participation']);
        (new \GlpiPlugin\Grcmanager\Training())->delete(['id' => $s['training']], true);
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
            \GlpiPlugin\Grcmanager\TrainingQuiz::displayTabContentForItem(new \Preference());
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('Quiz hameçonnage', $html);
            $this->assertStringContainsString('trainingquiz.send.php?id=' . $s['participation'], $html);

            $_SESSION['glpiID'] = $s['trainer'];
            ob_start();
            \GlpiPlugin\Grcmanager\TrainingQuiz::displayTabContentForItem(new \Preference());
            $this->assertStringNotContainsString('Quiz hameçonnage', (string) ob_get_clean());
        } finally {
            $_SESSION['glpiID'] = $saved;
        }
    }
}

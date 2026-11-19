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

use GlpiPlugin\Grcmanager\CrisisExercise as Exercise;
use GlpiPlugin\Grcmanager\CrisisExerciseReview as Review;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;
use GlpiPlugin\Grcmanager\Services\Exercise\ExerciseReview as Rules;

/**
 * Issue #732 : bilan après exercice, des objectifs fixés au lancement à la validation du rapport.
 */
final class CrisisExerciseReviewTest extends GrcmanagerIntegrationTestCase
{
    /** @var list<int> */
    private array $documents = [];

    /** @var list<array{class-string<\CommonDBTM>, int}> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->documents as $documentsId) {
            (new \Document())->delete(['id' => $documentsId], true);
        }
        foreach ($this->created as [$class, $id]) {
            (new $class())->delete(['id' => $id], true);
        }
        parent::tearDown();
    }

    private function launch(): Exercise
    {
        $exerciseId = Exercise::launch(
            'outage',
            ['Réunir la cellule en 30 min', '', 'Basculer sur le site de secours']
        );
        $this->assertGreaterThan(0, $exerciseId);
        $exercise = new Exercise();
        $this->assertTrue($exercise->getFromDB($exerciseId));

        return $exercise;
    }

    public function testReviewFromObjectivesToValidatedReport(): void
    {
        $exercise = $this->launch();
        $review = $exercise->collect()['review'];
        $this->assertIsArray($review);
        $this->assertSame(
            ['Réunir la cellule en 30 min', 'Basculer sur le site de secours'],
            array_column($review['objectives'], 'name'),
            'Objectifs du lancement, lignes vides écartées.'
        );
        $this->assertSame(1, Review::addObjectives($exercise, ['Prévenir les utilisateurs en 1 h']));
        $objectives = $exercise->collect()['review']['objectives'];
        $this->assertTrue(Review::deleteObjective($exercise, $objectives[2]['id']));
        $this->assertFalse(
            Review::evaluateObjective($exercise, $objectives[0]['id'], Rules::OUTCOME_MET, ''),
            'Évaluation après la fin de l\'exercice seulement.'
        );
        $this->assertSame(Rules::REFUSED_NOT_ENDED, Review::validate($exercise, ''));

        $findingId = Review::addFinding($exercise, 'Annuaire de crise périmé', 'Deux numéros faux.', 'major');
        $this->assertGreaterThan(0, $findingId);
        $minorId = Review::addFinding($exercise, 'Salle trop petite', '', 'inconnue');
        $this->assertTrue(Review::deleteFinding($exercise, $minorId));

        $this->documents[] = $exercise->finish();
        $this->assertFalse(Review::addObjectives($exercise, ['Trop tard']) > 0, 'Objectifs fixés avant la fin.');
        $this->assertSame(Rules::REFUSED_UNEVALUATED, Review::validate($exercise, ''));
        $this->assertFalse(Review::evaluateObjective($exercise, $objectives[0]['id'], 'excellent', ''));
        $this->assertTrue(
            Review::evaluateObjective($exercise, $objectives[0]['id'], Rules::OUTCOME_MET, 'En 25 min.')
        );
        $this->assertTrue(Review::evaluateObjective($exercise, $objectives[1]['id'], Rules::OUTCOME_PARTIAL, ''));

        // Plan d'amélioration : non-conformité créée à la demande, rattachée à l'incident d'exercice.
        $this->assertSame(0, countElementsInTable(Nonconformity::getTable(), [
            'plugin_grcmanager_securityincidents_id' => (int) $exercise->fields['plugin_grcmanager_securityincidents_id'],
        ]), 'Aucune action créée automatiquement.');
        $ncId = Review::createAction($exercise, $findingId, Review::ACTION_NONCONFORMITY, 2, '2000-01-01');
        $this->assertGreaterThan(0, $ncId);
        $this->created[] = [Nonconformity::class, $ncId];
        $this->assertSame(0, Review::createAction($exercise, $findingId, Review::ACTION_NONCONFORMITY, 2, ''));
        $this->assertFalse(Review::deleteFinding($exercise, $findingId), 'Un constat suivi d\'une action reste.');
        $nc = new Nonconformity();
        $nc->getFromDB($ncId);
        $this->assertSame('Annuaire de crise périmé', $nc->fields['title']);
        $this->assertSame('major', $nc->fields['severity']);
        $this->assertSame('open', $nc->fields['status']);
        $this->assertSame(2, (int) $nc->fields['users_id']);
        $this->assertSame((int) $exercise->fields['entities_id'], (int) $nc->fields['entities_id']);

        $review = $exercise->collect()['review'];
        $this->assertSame(Rules::ACTION_OVERDUE, $review['findings'][0]['action']['state']);
        $this->assertSame(1, $review['plan_summary']['overdue']);
        $this->assertSame(
            ['total' => 2, 'met' => 1, 'partial' => 1, 'not_met' => 0, 'pending' => 0],
            $review['objectives_summary']
        );

        // Validation : date et responsable, bilan figé, rapport validé archivé.
        $this->assertSame('', Review::validate($exercise, 'Bilan relu en comité.'));
        $this->assertTrue(Review::isValidated($exercise));
        $this->assertSame(2, (int) $exercise->fields['users_id_validator']);
        $this->assertSame(0, Review::addFinding($exercise, 'Après validation', '', 'minor'));
        $this->assertFalse(Review::evaluateObjective($exercise, $objectives[1]['id'], Rules::OUTCOME_MET, ''));
        $this->assertSame(Rules::REFUSED_VALIDATED, Review::validate($exercise, ''));
        $documentsId = $exercise->finish();
        $this->documents[] = $documentsId;
        $this->assertGreaterThan(0, $documentsId);

        $data = $exercise->collect();
        $this->assertTrue($data['exercise']['validated']);
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/pdf/crisis_exercise_report.html.twig',
            $data + ['report_title' => 'Rapport', 'generated_at_label' => '']
        );
        $this->assertStringContainsString('Réunir la cellule en 30 min', $html);
        $this->assertStringContainsString('Annuaire de crise périmé', $html);
        $this->assertStringContainsString('Bilan relu en comité.', $html);

        // Retour en brouillon : motif obligatoire, tracé dans l'historique.
        $this->assertFalse(Review::reopen($exercise, '  '));
        $this->assertTrue(Review::reopen($exercise, 'Constat oublié.'));
        $this->assertFalse(Review::isValidated($exercise));
        $history = $exercise->collect()['review']['history'];
        $this->assertSame([Rules::EVENT_VALIDATED, Rules::EVENT_REOPENED], array_column($history, 'action'));
        $this->assertSame('Constat oublié.', $history[1]['comment']);
        $this->assertGreaterThan(0, Review::addFinding($exercise, 'Constat oublié', '', 'minor'));

        // Statut, responsable et échéance de la non-conformité jamais modifiés par le bilan.
        $nc->getFromDB($ncId);
        $this->assertSame('open', $nc->fields['status']);
    }

    public function testTreatmentActionNeedsAnUpdatableRisk(): void
    {
        $exercise = $this->launch();
        $findingId = Review::addFinding($exercise, 'Sauvegarde non testée', '', 'critical');
        $this->assertSame(0, Review::createAction($exercise, $findingId, Review::ACTION_TREATMENT, 2, '', 999999));

        $riskId = (int) (new Risk())->add(['title' => 'PHPUnit perte du serveur de fichiers', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $riskId);
        $this->created[] = [Risk::class, $riskId];
        $this->assertArrayHasKey($riskId, Review::riskChoices());
        $actionId = Review::createAction($exercise, $findingId, Review::ACTION_TREATMENT, 2, '2099-12-31', $riskId);
        $this->assertGreaterThan(0, $actionId);
        $this->created[] = [RiskTreatmentAction::class, $actionId];
        $action = $exercise->collect()['review']['findings'][0]['action'];
        $this->assertSame(Review::ACTION_TREATMENT, $action['type']);
        $this->assertSame(Rules::ACTION_OPEN, $action['state']);
        $this->assertSame('Sauvegarde non testée', $action['title']);
    }

    public function testValidationRightIsDedicated(): void
    {
        $saved = $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = ALLSTANDARDRIGHT;
            $this->assertFalse(Review::canValidate(), 'Les droits standards ne suffisent pas.');
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = READ | Review::VALIDATE;
            $this->assertTrue(Review::canValidate());
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = $saved;
        }
    }

    public function testRealCrisisCellHasNoReview(): void
    {
        $incidentId = (int) (new \PluginGrcmanagerSecurityIncident())->add([
            'name' => 'PHPUnit panne réelle', 'entities_id' => 0, 'content' => 'Panne fictive.',
        ]);
        $this->created[] = [\PluginGrcmanagerSecurityIncident::class, $incidentId];
        $incident = new \PluginGrcmanagerSecurityIncident();
        $incident->getFromDB($incidentId);
        $cell = new Exercise();
        $this->assertTrue($cell->getFromDB(Exercise::activate($incident, 'outage')));
        $this->assertNull($cell->collect()['review']);
        $this->assertSame(0, Review::addFinding($cell, 'Hors bilan', '', 'minor'));
    }
}

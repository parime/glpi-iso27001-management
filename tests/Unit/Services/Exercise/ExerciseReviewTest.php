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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Exercise;

use GlpiPlugin\Grcmanager\Services\Exercise\ExerciseReview;
use PHPUnit\Framework\TestCase;

/**
 * Issue #732 : règles du bilan après exercice.
 */
final class ExerciseReviewTest extends TestCase
{
    public function testObjectivesAreSplitOnePerLineWithoutBlanksOrDuplicates(): void
    {
        $this->assertSame(
            ['Réunir la cellule en 45 min', 'Restaurer le serveur de fichiers'],
            ExerciseReview::splitObjectives(
                "  Réunir la cellule en 45 min \r\n\r\nRestaurer le serveur de fichiers\nRéunir la cellule en 45 min"
            )
        );
        $this->assertSame([], ExerciseReview::splitObjectives(" \n\t\n"));
        $lines = array_map(static fn (int $i): string => "Objectif $i", range(1, 30));
        $many = ExerciseReview::splitObjectives(implode("\n", $lines));
        $this->assertCount(ExerciseReview::MAX_OBJECTIVES, $many);
        $this->assertSame(255, mb_strlen(ExerciseReview::splitObjectives(str_repeat('é', 400))[0]));
    }

    public function testUnknownValuesAreNormalized(): void
    {
        $this->assertSame('', ExerciseReview::normalizeOutcome('excellent'));
        $this->assertSame(ExerciseReview::OUTCOME_PARTIAL, ExerciseReview::normalizeOutcome('partial'));
        $this->assertSame('minor', ExerciseReview::normalizeSeverity('<b>'));
        $this->assertSame('critical', ExerciseReview::normalizeSeverity('critical'));
    }

    public function testObjectivesSummaryCountsEachOutcome(): void
    {
        $this->assertSame(
            ['total' => 5, 'met' => 2, 'partial' => 1, 'not_met' => 1, 'pending' => 1],
            ExerciseReview::objectivesSummary(['met', 'partial', '', 'not_met', 'met'])
        );
    }

    public function testValidationNeedsAFinishedExerciseWithEveryObjectiveEvaluated(): void
    {
        $this->assertSame(ExerciseReview::REFUSED_NOT_ENDED, ExerciseReview::validationRefusal(false, false, ['met']));
        $this->assertSame(ExerciseReview::REFUSED_VALIDATED, ExerciseReview::validationRefusal(true, true, ['met']));
        $this->assertSame(ExerciseReview::REFUSED_NO_OBJECTIVE, ExerciseReview::validationRefusal(true, false, []));
        $this->assertSame(
            ExerciseReview::REFUSED_UNEVALUATED,
            ExerciseReview::validationRefusal(true, false, ['met', ''])
        );
        $this->assertSame('', ExerciseReview::validationRefusal(true, false, ['met', 'not_met']));
    }

    public function testActionStateFlagsOverdueActions(): void
    {
        $this->assertSame(ExerciseReview::ACTION_DONE, ExerciseReview::actionState(true, '2000-01-01', '2099-06-01'));
        $this->assertSame(
            ExerciseReview::ACTION_OVERDUE,
            ExerciseReview::actionState(false, '2099-05-31', '2099-06-01 10:00:00')
        );
        $this->assertSame(ExerciseReview::ACTION_OPEN, ExerciseReview::actionState(false, '2099-06-01', '2099-06-01'));
        $this->assertSame(ExerciseReview::ACTION_OPEN, ExerciseReview::actionState(false, null, '2099-06-01'));
    }

    public function testPlanSummary(): void
    {
        $this->assertSame(
            ['findings' => 4, 'actions' => 3, 'done' => 1, 'overdue' => 1, 'without_action' => 1],
            ExerciseReview::planSummary(['done', '', 'overdue', 'open'])
        );
    }
}

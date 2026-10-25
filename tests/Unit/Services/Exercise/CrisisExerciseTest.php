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

use GlpiPlugin\Grcmanager\Services\Exercise\CrisisExercise;
use PHPUnit\Framework\TestCase;

final class CrisisExerciseTest extends TestCase
{
    public function testStepStatus(): void
    {
        $this->assertSame(CrisisExercise::STEP_ON_TIME, CrisisExercise::stepStatus(30, 30, false));
        $this->assertSame(CrisisExercise::STEP_LATE, CrisisExercise::stepStatus(31, 30, true));
        $this->assertSame(CrisisExercise::STEP_PENDING, CrisisExercise::stepStatus(null, 30, false));
        $this->assertSame(CrisisExercise::STEP_MISSED, CrisisExercise::stepStatus(null, 30, true));
    }

    public function testMinutesSince(): void
    {
        $this->assertSame(95, CrisisExercise::minutesSince('2099-01-01 08:00:00', '2099-01-01 09:35:59'));
        $this->assertNull(CrisisExercise::minutesSince('2099-01-01 08:00:00', null));
        $this->assertSame(0, CrisisExercise::minutesSince('2099-01-01 08:00:00', '2099-01-01 07:00:00'));
    }

    public function testSummary(): void
    {
        $summary = CrisisExercise::summary([
            ['minutes' => 10, 'status' => CrisisExercise::STEP_ON_TIME],
            ['minutes' => 50, 'status' => CrisisExercise::STEP_LATE],
            ['minutes' => null, 'status' => CrisisExercise::STEP_MISSED],
            ['minutes' => 20, 'status' => CrisisExercise::STEP_ON_TIME],
        ]);
        $this->assertSame(['total' => 4, 'done' => 3, 'on_time' => 2, 'average' => 27, 'rate' => 50], $summary);
        $this->assertSame(
            ['total' => 0, 'done' => 0, 'on_time' => 0, 'average' => null, 'rate' => 0],
            CrisisExercise::summary([])
        );
    }

    public function testScenariosAreOrderedByTarget(): void
    {
        foreach (array_keys(CrisisExercise::SCENARIOS) as $scenario) {
            $targets = array_column(CrisisExercise::orderedSteps($scenario), 1);
            $sorted = $targets;
            sort($sorted);
            $this->assertSame($sorted, $targets, $scenario);
        }
        $this->assertSame([], CrisisExercise::orderedSteps('inconnu'));
    }
}

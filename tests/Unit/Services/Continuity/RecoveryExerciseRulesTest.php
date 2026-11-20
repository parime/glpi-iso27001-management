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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Continuity;

use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryExerciseRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #733 : exercices de reprise d'un processus fictif « Facturation » (type d'exercice, perte de
 * données mesurée, programme de tests, couverture pluriannuelle).
 */
final class RecoveryExerciseRulesTest extends TestCase
{
    public function testTypesFromFormKeepCheckedDefaultsThenCustomTypesWithoutDuplicates(): void
    {
        $types = Rules::fromForm(
            [Rules::TYPE_TABLETOP, Rules::TYPE_FULL_SCALE, 'unknown'],
            "Supplier failover drill\n  supplier FAILOVER drill \n\n<b>Restore</b> from tape\n"
        );

        $this->assertSame(
            [Rules::TYPE_TABLETOP, Rules::TYPE_FULL_SCALE, 'Supplier failover drill', 'Restore from tape'],
            $types
        );
        $this->assertSame(['Supplier failover drill', 'Restore from tape'], Rules::customTypes($types));
    }

    public function testTypeListIsBounded(): void
    {
        $many = array_map(static fn (int $i): string => 'Type ' . $i, range(1, 50));
        $this->assertCount(Rules::MAX_TYPES, Rules::sanitizeTypes($many));
        $this->assertSame(Rules::MAX_TYPE_LENGTH, mb_strlen(Rules::sanitizeType(str_repeat('é', 200))));
        $this->assertSame('', Rules::sanitizeType(['array']));
    }

    public function testChosenTypeMustBeOfferedOrAlreadyCurrent(): void
    {
        $allowed = [Rules::TYPE_TABLETOP, 'Supplier failover drill'];

        $this->assertSame(Rules::TYPE_TABLETOP, Rules::chooseType('tabletop', $allowed));
        $this->assertSame('', Rules::chooseType('full_scale', $allowed));
        $this->assertSame('full_scale', Rules::chooseType('full_scale', $allowed, 'full_scale'));
        $this->assertSame('', Rules::chooseType('', $allowed));
    }

    public function testMeasuredDataLossDistinguishesNotMeasuredFromNoLoss(): void
    {
        $this->assertNull(Rules::sanitizeRpoMinutes(''));
        $this->assertNull(Rules::sanitizeRpoMinutes(null));
        $this->assertNull(Rules::sanitizeRpoMinutes('abc'));
        $this->assertNull(Rules::sanitizeRpoMinutes(-5));
        $this->assertNull(Rules::sanitizeRpoMinutes(10_000_000));
        $this->assertSame(0, Rules::sanitizeRpoMinutes('0'));
        $this->assertSame(90, Rules::sanitizeRpoMinutes('90'));
    }

    public function testDataLossIsComparedWithTheRpo(): void
    {
        // RPO de 4 h : 240 min tiennent, 241 min dépassent.
        $this->assertFalse(Rules::exceedsRpo(4, 240));
        $this->assertTrue(Rules::exceedsRpo(4, 241));
        // RPO non défini ou perte non mesurée : rien à comparer.
        $this->assertFalse(Rules::exceedsRpo(0, 5000));
        $this->assertFalse(Rules::exceedsRpo(4, null));

        $this->assertSame(Rules::RESULT_PASSED, Rules::testResult(false, false));
        $this->assertSame(Rules::RESULT_FAILED, Rules::testResult(true, false));
        $this->assertSame(Rules::RESULT_FAILED, Rules::testResult(false, true));
    }

    public function testFrequencyOfTheProcessOverridesTheDefault(): void
    {
        $this->assertSame(0, Rules::sanitizeFrequencyMonths('abc'));
        $this->assertSame(0, Rules::sanitizeFrequencyMonths(61));
        $this->assertSame(6, Rules::sanitizeFrequencyMonths('6'));
        $this->assertSame(6, Rules::effectiveMonths(6, 12));
        $this->assertSame(12, Rules::effectiveMonths(0, 12));
    }

    public function testDueDateStartsFromTheLastTestOrTheCreationOfTheProcess(): void
    {
        $this->assertSame('2026-03-15', Rules::dueBaseDate('2026-03-15', '2025-01-01 10:00:00'));
        $this->assertSame('2025-01-01', Rules::dueBaseDate(null, '2025-01-01 10:00:00'));
        $this->assertNull(Rules::dueBaseDate('', ''));
    }

    public function testYearlyCoverageKeepsTheLastTestOfEachYear(): void
    {
        $years = Rules::coverageYears(2026);
        $this->assertSame([2023, 2024, 2025, 2026], $years);

        $coverage = Rules::yearlyCoverage([
            ['id' => 1, 'test_date' => '2024-02-01', 'result' => Rules::RESULT_FAILED],
            // Même année, plus récent : la reprise a été corrigée.
            ['id' => 2, 'test_date' => '2024-09-01', 'result' => Rules::RESULT_PASSED],
            ['id' => 4, 'test_date' => '2026-05-10', 'result' => Rules::RESULT_PASSED],
            // Même date, saisi après : il l'emporte.
            ['id' => 5, 'test_date' => '2026-05-10', 'result' => Rules::RESULT_FAILED],
            // Hors période affichée.
            ['id' => 3, 'test_date' => '2019-01-01', 'result' => Rules::RESULT_FAILED],
        ], $years);

        $this->assertSame([
            2023 => Rules::RESULT_UNTESTED,
            2024 => Rules::RESULT_PASSED,
            2025 => Rules::RESULT_UNTESTED,
            2026 => Rules::RESULT_FAILED,
        ], $coverage);
    }
}

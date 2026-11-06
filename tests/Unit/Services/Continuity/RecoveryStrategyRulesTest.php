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

use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use PHPUnit\Framework\TestCase;

final class RecoveryStrategyRulesTest extends TestCase
{
    public function testMinutesAndDatesAreSanitized(): void
    {
        $this->assertSame(90, RecoveryStrategyRules::sanitizeMinutes('90'));
        $this->assertSame(0, RecoveryStrategyRules::sanitizeMinutes(-5));
        $this->assertSame(0, RecoveryStrategyRules::sanitizeMinutes('abc'));
        $this->assertSame(0, RecoveryStrategyRules::sanitizeMinutes(RecoveryStrategyRules::MAX_MINUTES + 1));
        $this->assertSame('2026-02-28', RecoveryStrategyRules::sanitizeDate('2026-02-28'));
        $this->assertNull(RecoveryStrategyRules::sanitizeDate('2026-02-30'));
        $this->assertNull(RecoveryStrategyRules::sanitizeDate("2026-01-01' OR 1=1"));
        $this->assertNull(RecoveryStrategyRules::sanitizeDate(null));
    }

    public function testLastTestIsTheMostRecentOne(): void
    {
        $this->assertNull(RecoveryStrategyRules::lastTest([]));
        $last = RecoveryStrategyRules::lastTest([
            ['id' => 3, 'test_date' => '2026-03-01', 'measured_minutes' => 300],
            ['id' => 1, 'test_date' => '2026-06-01', 'measured_minutes' => 120],
            ['id' => 2, 'test_date' => '2026-06-01', 'measured_minutes' => 90],
        ]);
        $this->assertSame(2, $last['id'] ?? null, 'Même date : la dernière saisie l\'emporte.');
    }

    public function testStrategyIssues(): void
    {
        $this->assertSame(
            ['strategy_missing', 'recovery_untested'],
            RecoveryStrategyRules::strategyIssues(4, ['', ' ', ''], null)
        );
        $this->assertSame([], RecoveryStrategyRules::strategyIssues(4, ['Saisie papier', '', ''], 240));
        $this->assertSame(
            ['recovery_above_rto'],
            RecoveryStrategyRules::strategyIssues(4, ['', 'Site de secours', ''], 241)
        );
        $this->assertSame([], RecoveryStrategyRules::strategyIssues(0, ['', '', 'Procédure'], 9999), 'Sans RTO.');
        $this->assertTrue(RecoveryStrategyRules::exceedsRto(1, 61));
        $this->assertFalse(RecoveryStrategyRules::exceedsRto(1, 60));
    }

    public function testSplit(): void
    {
        $this->assertSame(['hours' => 2, 'minutes' => 5], RecoveryStrategyRules::split(125));
        $this->assertSame(['hours' => 0, 'minutes' => 0], RecoveryStrategyRules::split(-3));
    }
}

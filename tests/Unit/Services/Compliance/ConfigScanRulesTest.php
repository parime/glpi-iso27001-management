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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Compliance\ConfigScanRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #781 : conformité de configuration, logique pure sur des rapports fictifs.
 */
final class ConfigScanRulesTest extends TestCase
{
    public function testRateCountsOnlyEvaluatedRules(): void
    {
        $this->assertSame(50, R::rate(1, 1, 0));
        $this->assertSame(66, R::rate(1, 1, 0, 1), 'Fixed rules count as compliant.');
        $this->assertSame(33, R::rate(1, 1, 1));
        $this->assertNull(R::rate(0, 0, 0));
    }

    public function testColors(): void
    {
        $this->assertSame('success', R::rateColor(96));
        $this->assertSame('warning', R::rateColor(80));
        $this->assertSame('danger', R::rateColor(79));
        $this->assertSame('secondary', R::rateColor(null));
        $this->assertSame('danger', R::severityColor('high'));
        $this->assertSame('secondary', R::severityColor('bogus'));
    }

    public function testCandidateNamesSkipAddressesAndAddShortNames(): void
    {
        $this->assertSame(
            ['srv-01', 'srv-01.example.org'],
            R::candidateNames(['hostname' => 'SRV-01', 'target' => '192.0.2.4', 'fqdn' => 'srv-01.example.org.'])
        );
        $this->assertSame([], R::candidateNames(['target' => '2001:db8::1']));
    }

    public function testLatestPreviousAndTrend(): void
    {
        $reports = [
            ['id' => 1, 'computers_id' => 5, 'hostname' => 'a', 'profile_id' => 'p', 'scan_date' => '2026-01-01', 'rate' => 60],
            ['id' => 2, 'computers_id' => 5, 'hostname' => 'a', 'profile_id' => 'p', 'scan_date' => '2026-02-01', 'rate' => 75],
            ['id' => 3, 'computers_id' => 0, 'hostname' => 'b', 'profile_id' => 'p', 'scan_date' => '2026-02-01', 'rate' => 90],
            ['id' => 4, 'computers_id' => 5, 'hostname' => 'a', 'profile_id' => 'q', 'scan_date' => '2026-01-15', 'rate' => 40],
        ];

        $this->assertSame([2, 3, 4], array_column(R::latestPerTarget($reports), 'id'));
        $previous = R::previousRates($reports);
        $this->assertNull($previous[1]);
        $this->assertSame(60, $previous[2]);
        $this->assertNull($previous[4], 'Another profile is not compared.');
        $this->assertSame(15, R::trend(60, 75));
        $this->assertNull(R::trend(null, 75));
    }

    public function testTopFailingCountsTargetsOnce(): void
    {
        $top = R::topFailing([
            ['rule_id' => 'r1', 'title' => '', 'severity' => 'low', 'target' => 'c1'],
            ['rule_id' => 'r1', 'title' => 'Rule one', 'severity' => 'low', 'target' => 'c2'],
            ['rule_id' => 'r1', 'title' => 'Rule one', 'severity' => 'low', 'target' => 'c2'],
            ['rule_id' => 'r2', 'title' => 'Rule two', 'severity' => 'high', 'target' => 'c1'],
            ['rule_id' => 'r3', 'title' => 'Rule three', 'severity' => 'medium', 'target' => 'c1'],
        ], 2);

        $this->assertSame(['r1', 'r2'], array_column($top, 'rule_id'));
        $this->assertSame(2, $top[0]['count']);
        $this->assertSame('Rule one', $top[0]['title']);
    }

    public function testSummary(): void
    {
        $summary = R::summary([
            ['computers_id' => 1, 'hostname' => 'a', 'rate' => 50, 'high_fail_count' => 2],
            ['computers_id' => 2, 'hostname' => 'b', 'rate' => 100, 'high_fail_count' => 0],
            ['computers_id' => 0, 'hostname' => 'c', 'rate' => null, 'high_fail_count' => 0],
        ]);

        $this->assertSame(3, $summary['targets']);
        $this->assertSame(75, $summary['average']);
        $this->assertSame(1, $summary['below_target']);
        $this->assertSame(1, $summary['with_high']);
        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame(33, $summary['high_rate']);
        $this->assertNull(R::summary([])['average']);
    }
}

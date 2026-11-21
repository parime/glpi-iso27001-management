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

use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #854 : retard des correctifs de sécurité, logique pure sur des données fictives.
 */
final class PatchLagRulesTest extends TestCase
{
    public function testUpdateSoftwareRecognition(): void
    {
        $this->assertTrue(R::isUpdateSoftware('KB5031356'));
        $this->assertTrue(R::isUpdateSoftware('Security Update for Windows (KB5031356)'));
        $this->assertTrue(R::isUpdateSoftware('Anything', 'Hotfix'));
        $this->assertTrue(R::isUpdateSoftware('Anything', 'System > Updates'));
        $this->assertTrue(R::isUpdateSoftware('Anything', '', true), 'Marked as update in GLPI.');
        $this->assertFalse(R::isUpdateSoftware('Firefox'));
        $this->assertFalse(R::isUpdateSoftware('KBox player'), 'No KB article number.');
        $this->assertFalse(R::isUpdateSoftware('Office', 'Office suites'));
    }

    public function testLastPatchSource(): void
    {
        $this->assertSame(
            ['date' => '2026-03-01', 'source' => R::SOURCE_UPDATE],
            R::lastPatch('2026-03-01 10:00:00', '2026-04-01', 'Microsoft Windows 11 Pro')
        );
        $this->assertSame(
            ['date' => '2026-04-01', 'source' => R::SOURCE_PACKAGE],
            R::lastPatch('', '2026-04-01', 'Debian GNU/Linux 12 (bookworm)')
        );
        $this->assertSame(
            ['date' => '', 'source' => R::SOURCE_NONE],
            R::lastPatch('', '2026-04-01', 'Microsoft Windows Server 2022'),
            'An application install is not a Windows patch.'
        );
        $this->assertSame(['date' => '', 'source' => R::SOURCE_NONE], R::lastPatch('', '2026-04-01', ''));
        $this->assertSame(['date' => '', 'source' => R::SOURCE_NONE], R::lastPatch('2026-02-30', '', 'Ubuntu'));
    }

    public function testAgeAndStatus(): void
    {
        $this->assertSame(10, R::ageDays('2026-01-01', '2026-01-11'));
        $this->assertSame(0, R::ageDays('2026-02-01', '2026-01-11'), 'Future date.');
        $this->assertNull(R::ageDays('', '2026-01-11'));
        $this->assertSame(R::STATUS_UNKNOWN, R::status(null, 15));
        $this->assertSame(R::STATUS_UP_TO_DATE, R::status(15, 15));
        $this->assertSame(R::STATUS_LATE, R::status(16, 15));
        $this->assertTrue(R::isStaleInventory('', '2026-01-11'));
        $this->assertTrue(R::isStaleInventory('2025-11-01 08:00:00', '2026-01-11'));
        $this->assertFalse(R::isStaleInventory('2026-01-01 08:00:00', '2026-01-11'));
    }

    public function testTierAndTargets(): void
    {
        $this->assertSame(R::TIER_CRITICAL, R::tier('low', 'high', '', false));
        $this->assertSame(R::TIER_IMPORTANT, R::tier('medium', 'low', '', false));
        $this->assertSame(R::TIER_STANDARD, R::tier('', '', '', false));
        $this->assertSame(R::TIER_CRITICAL, R::tier('', '', '', true), 'Published on the Internet.');
        $this->assertSame(R::DEFAULT_TARGETS, R::normalizeTargets(null));
        $this->assertSame(
            [R::TIER_CRITICAL => 7, R::TIER_IMPORTANT => 30, R::TIER_STANDARD => 60],
            R::normalizeTargets(['critical' => '7', 'important' => 0, 'standard' => 'abc'])
        );
        $this->assertSame(60, R::normalizeTargets(['standard' => 9999])[R::TIER_STANDARD]);
    }

    public function testServerDetection(): void
    {
        $this->assertTrue(R::isServer('Server', ''));
        $this->assertTrue(R::isServer('Rack Mount Chassis', ''));
        $this->assertTrue(R::isServer('', 'Microsoft Windows Server 2022 Standard'));
        $this->assertFalse(R::isServer('Desktop', 'Microsoft Windows 11 Pro'));
        $this->assertFalse(R::isServer('', 'Ubuntu 24.04'));
    }

    public function testSortAndSummary(): void
    {
        $rows = [
            ['name' => 'b', 'status' => R::STATUS_UP_TO_DATE, 'tier' => R::TIER_CRITICAL, 'exposed' => false,
                'overdue' => 0, 'is_server' => true],
            ['name' => 'c', 'status' => R::STATUS_LATE, 'tier' => R::TIER_STANDARD, 'exposed' => false,
                'overdue' => 50, 'is_server' => false],
            ['name' => 'a', 'status' => R::STATUS_LATE, 'tier' => R::TIER_CRITICAL, 'exposed' => true,
                'overdue' => 2, 'is_server' => true],
            ['name' => 'd', 'status' => R::STATUS_UNKNOWN, 'tier' => R::TIER_STANDARD, 'exposed' => false,
                'overdue' => 0, 'is_server' => false],
        ];
        $this->assertSame(['a', 'c', 'd', 'b'], array_column(R::sort($rows), 'name'));
        $summary = R::summary($rows);
        $this->assertSame(2, $summary[R::STATUS_LATE]);
        $this->assertSame(1, $summary[R::STATUS_UNKNOWN]);
        $this->assertSame(33, $summary['rate']);
        $this->assertSame(1, $summary['late_critical_servers']);
        $this->assertSame(1, $summary['late_exposed']);
        $this->assertNull(R::summary([])['rate']);
        $this->assertSame(2, R::byTier($rows)[R::TIER_CRITICAL]['total']);
    }
}

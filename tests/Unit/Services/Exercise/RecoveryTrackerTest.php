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

use GlpiPlugin\Grcmanager\Services\Exercise\RecoveryTracker;
use PHPUnit\Framework\TestCase;

final class RecoveryTrackerTest extends TestCase
{
    private const START = '2099-01-01 08:00:00';

    public function testNotRestoredYet(): void
    {
        $this->assertSame(
            ['state' => RecoveryTracker::RUNNING, 'hours' => 2.5, 'due' => '2099-01-01 12:00:00'],
            RecoveryTracker::status(self::START, 4, null, '2099-01-01 10:30:00')
        );
        $this->assertSame(
            RecoveryTracker::OVERDUE,
            RecoveryTracker::status(self::START, 4, null, '2099-01-01 12:00:01')['state']
        );
        $this->assertSame(
            ['state' => RecoveryTracker::NO_RTO, 'hours' => 1.0, 'due' => null],
            RecoveryTracker::status(self::START, 0, null, '2099-01-01 09:00:00')
        );
    }

    public function testRestored(): void
    {
        $this->assertSame(
            ['state' => RecoveryTracker::RESTORED_ON_TIME, 'hours' => 4.0, 'due' => '2099-01-01 12:00:00'],
            RecoveryTracker::status(self::START, 4, '2099-01-01 12:00:00', '2099-01-03 00:00:00')
        );
        $this->assertSame(
            RecoveryTracker::RESTORED_LATE,
            RecoveryTracker::status(self::START, 1, '2099-01-01 09:30:00', '2099-01-03 00:00:00')['state']
        );
        $this->assertSame(
            RecoveryTracker::RESTORED,
            RecoveryTracker::status(self::START, 0, '2099-01-01 09:30:00', '2099-01-03 00:00:00')['state']
        );
    }
}

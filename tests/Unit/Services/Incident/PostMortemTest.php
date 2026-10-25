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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\PostMortem;
use PHPUnit\Framework\TestCase;

final class PostMortemTest extends TestCase
{
    public function testDeadlineOutcome(): void
    {
        $due = '2099-01-03 10:00:00';
        $this->assertSame(PostMortem::DEADLINE_MET, PostMortem::deadlineOutcome('2099-01-03 09:59:00', $due));
        $this->assertSame(PostMortem::DEADLINE_LATE, PostMortem::deadlineOutcome('2099-01-03 10:01:00', $due));
        $this->assertSame(PostMortem::DEADLINE_MISSING, PostMortem::deadlineOutcome(null, $due));
        $this->assertSame(PostMortem::DEADLINE_MISSING, PostMortem::deadlineOutcome('', $due));
    }

    public function testCostTotalUsesGlpiFormula(): void
    {
        $this->assertSame(275.0, PostMortem::costTotal([
            ['actiontime' => 7200, 'cost_time' => 50, 'cost_fixed' => 100, 'cost_material' => 75],
        ]));
        $this->assertSame(0.0, PostMortem::costTotal([]));
    }

    public function testTimelineIsSortedAndStable(): void
    {
        $sorted = PostMortem::sortTimeline([
            ['date' => '2099-01-02 10:00:00', 'kind' => 'b'],
            ['date' => '2099-01-01 08:00:00', 'kind' => 'a'],
            ['date' => '2099-01-02 10:00:00', 'kind' => 'c'],
        ]);
        $this->assertSame(['a', 'b', 'c'], array_column($sorted, 'kind'));
    }

    public function testHoursBetween(): void
    {
        $this->assertSame(26.5, PostMortem::hoursBetween('2099-01-01 08:00:00', '2099-01-02 10:30:00'));
        $this->assertNull(PostMortem::hoursBetween('2099-01-01 08:00:00', null));
    }
}

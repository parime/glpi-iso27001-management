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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Maturity;

use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;
use PHPUnit\Framework\TestCase;

final class MaturityScoreTest extends TestCase
{
    public function testImplementationRateCountsInProgressAsHalf(): void
    {
        $this->assertSame(50.0, MaturityScore::implementationRate([
            'implemented' => 2, 'verified' => 1, 'in_progress' => 2, 'not_started' => 3,
        ]));
        $this->assertNull(MaturityScore::implementationRate([]));
        $this->assertSame(50.0, MaturityScore::rate(1, 2));
        $this->assertNull(MaturityScore::rate(3, 0));
    }

    public function testFullScoreAndLevels(): void
    {
        $all = ['soa' => 100.0, 'isms' => 100.0, 'evidence' => 100.0, 'audit' => 100.0, 'training' => 100.0];
        $result = MaturityScore::compute($all, ['capa_overdue' => 0, 'incidents_open' => 0]);
        $this->assertSame(100, $result['score']);
        $this->assertSame(5, $result['level']);
        $this->assertSame(1, MaturityScore::level(19));
        $this->assertSame(3, MaturityScore::level(40));
    }

    public function testMissingComponentWeightIsRedistributed(): void
    {
        // Sans formations ni preuves : les 75 % restants sont ramenés à 100 %.
        $components = ['soa' => 50.0, 'isms' => 50.0, 'evidence' => null, 'audit' => 50.0, 'training' => null];
        $result = MaturityScore::compute($components, ['capa_overdue' => 0, 'incidents_open' => 0]);
        $this->assertSame(50, $result['score']);
        $this->assertSame(0.0, $result['components']['training']['effective_weight']);
        $this->assertSame(53.3, $result['components']['soa']['effective_weight']);
    }

    public function testPenaltiesAreCapped(): void
    {
        $all = ['soa' => 80.0, 'isms' => 80.0, 'evidence' => 80.0, 'audit' => 80.0, 'training' => 80.0];
        $this->assertSame(74, MaturityScore::compute($all, ['capa_overdue' => 2, 'incidents_open' => 1])['score']);
        $capped = MaturityScore::compute($all, ['capa_overdue' => 50, 'incidents_open' => 50]);
        $this->assertSame(20, $capped['penalty'], 'Au plus 10 points par catégorie.');
        $this->assertSame(60, $capped['score']);
    }
}

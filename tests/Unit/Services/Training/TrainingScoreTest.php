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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Training;

use GlpiPlugin\Grcmanager\Services\Training\TrainingScore;
use PHPUnit\Framework\TestCase;

final class TrainingScoreTest extends TestCase
{
    public function testResultFromThreshold(): void
    {
        $this->assertSame('passed', TrainingScore::result(32.0, 40, 80), '32/40 = 80 % : seuil atteint.');
        $this->assertSame('failed', TrainingScore::result(31.5, 40, 80));
        $this->assertNull(TrainingScore::result(null, 40, 80), 'Pas encore noté.');
        $this->assertNull(TrainingScore::result(30.0, 40, 0), 'Formation sans seuil.');
        $this->assertNull(TrainingScore::result(30.0, 0, 80), 'Formation sans note maximale.');
    }

    public function testParseRejectsOutOfRangeAndAcceptsComma(): void
    {
        $this->assertSame(32.5, TrainingScore::parse(' 32,5 ', 40));
        $this->assertSame(0.0, TrainingScore::parse('0', 40));
        $this->assertNull(TrainingScore::parse('41', 40));
        $this->assertNull(TrainingScore::parse('-1', 40));
        $this->assertNull(TrainingScore::parse('abc', 40));
        $this->assertNull(TrainingScore::parse('', 40));
    }

    public function testSessionRateIgnoresParticipantsWithoutAssessment(): void
    {
        $rate = TrainingScore::sessionRate(['passed', 'passed', 'failed', 'not_applicable', 'passed']);
        $this->assertSame(['passed' => 3, 'assessed' => 4, 'rate' => 75], $rate);
        $this->assertNull(TrainingScore::sessionRate(['not_applicable'])['rate']);
    }

    public function testFormat(): void
    {
        $this->assertSame('32/40 (80 %)', TrainingScore::format(32.0, 40));
        $this->assertSame('32,5/40 (81 %)', TrainingScore::format(32.5, 40));
    }
}

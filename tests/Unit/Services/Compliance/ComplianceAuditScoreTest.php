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

use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore as Score;
use PHPUnit\Framework\TestCase;

final class ComplianceAuditScoreTest extends TestCase
{
    public function testRate(): void
    {
        $this->assertSame(85, Score::rate(15, 100));
        $this->assertSame(0, Score::rate(12, 10));
        $this->assertSame(100, Score::rate(0, 3));
        $this->assertNull(Score::rate(0, 0));
    }

    public function testFrameworkScoresAverageEvaluatedChecksOnly(): void
    {
        $scores = Score::frameworkScores([
            'antivirus'         => ['gaps' => 1, 'total' => 4, 'evaluated' => true],   // 75
            'encryption'        => ['gaps' => 0, 'total' => 0, 'evaluated' => true],   // sans objet
            'inventory'         => ['gaps' => 5, 'total' => 10, 'evaluated' => true],  // 50
            'change_validation' => ['gaps' => 0, 'total' => 2, 'evaluated' => true],   // 100
            'accounts'          => ['gaps' => 9, 'total' => 10, 'evaluated' => false], // droit manquant
        ]);
        $this->assertSame(['score' => 75, 'checks' => 3], $scores[Score::ISO27001]);
        $this->assertSame(['score' => 75, 'checks' => 3], $scores[Score::NIS2]);
        $this->assertSame(['score' => 75, 'checks' => 1], $scores[Score::GDPR]);
        $this->assertSame(['score' => null, 'checks' => 0], Score::frameworkScores([])[Score::GDPR]);
    }

    public function testAntivirusCompliance(): void
    {
        $ok = ['is_active' => 1, 'is_uptodate' => 1, 'date_expiration' => null];
        $this->assertTrue(Score::isAntivirusCompliant($ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['is_uptodate' => 0] + $ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['is_active' => 0] + $ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['date_expiration' => '2098-12-31'] + $ok, '2099-01-01'));
        $this->assertTrue(Score::isAntivirusCompliant(['date_expiration' => '2099-01-01'] + $ok, '2099-01-01'));
    }
}

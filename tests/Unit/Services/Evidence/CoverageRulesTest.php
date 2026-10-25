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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Evidence;

use GlpiPlugin\Grcmanager\Services\Evidence\CoverageRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #164 : taux de couverture des preuves.
 */
final class CoverageRulesTest extends TestCase
{
    public function testRateIsFlooredAndNullWithoutAssets(): void
    {
        $this->assertNull(CoverageRules::rate(0, 0));
        $this->assertSame(0, CoverageRules::rate(0, 3));
        $this->assertSame(99, CoverageRules::rate(299, 300), 'Pas « 100 % » tant qu\'un actif manque.');
        $this->assertSame(100, CoverageRules::rate(5, 5));
        $this->assertSame(100, CoverageRules::rate(7, 5), 'Borné.');
    }

    public function testLevels(): void
    {
        $this->assertSame('none', CoverageRules::level(null));
        $this->assertSame('full', CoverageRules::level(100));
        $this->assertSame('partial', CoverageRules::level(80));
        $this->assertSame('low', CoverageRules::level(79));
    }

    public function testTypeField(): void
    {
        $this->assertSame('networkequipmenttypes_id', CoverageRules::typeField('NetworkEquipment'));
        $this->assertSame('computertypes_id', CoverageRules::typeField('Computer'));
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Security360;

use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #837 : score et niveau de la vue « Sécurité 360° » d'un actif.
 */
final class Security360RulesTest extends TestCase
{
    public function testNoAssessedSourceGivesNoScore(): void
    {
        $this->assertSame(Rules::LEVEL_NONE, Rules::score([])['level']);
        $result = Rules::score([
            ['status' => Rules::STATUS_INFO, 'weight' => 3],
            ['status' => Rules::STATUS_OK, 'weight' => 0],
        ]);
        $this->assertNull($result['score']);
        $this->assertSame(0, $result['evaluated']);
    }

    public function testWeightedAverage(): void
    {
        $result = Rules::score([
            ['status' => Rules::STATUS_OK, 'weight' => 3],
            ['status' => Rules::STATUS_WARNING, 'weight' => 1],
            ['status' => Rules::STATUS_INFO, 'weight' => 2],
        ]);
        $this->assertSame(88, $result['score']);
        $this->assertSame(Rules::LEVEL_GOOD, $result['level']);
        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(1, $result['warning']);
    }

    public function testOneCriticalSourcePreventsTheGoodLevel(): void
    {
        $result = Rules::score([
            ['status' => Rules::STATUS_OK, 'weight' => 5],
            ['status' => Rules::STATUS_CRITICAL, 'weight' => 1],
        ]);
        $this->assertSame(83, $result['score']);
        $this->assertSame(Rules::LEVEL_FAIR, $result['level']);
        $this->assertSame(Rules::LEVEL_POOR, Rules::level(49, false));
        $this->assertSame(Rules::LEVEL_FAIR, Rules::level(50, false));
    }

    public function testNormalizeDropsEmptyResultsAndUnknownStatuses(): void
    {
        $this->assertNull(Rules::normalize(null));
        $this->assertNull(Rules::normalize(['status' => Rules::STATUS_OK, 'summary' => ' ']));
        $row = Rules::normalize(['status' => 'strange', 'summary' => 'x', 'facts' => [1]]);
        $this->assertSame(['status' => Rules::STATUS_INFO, 'summary' => 'x', 'url' => '', 'facts' => ['1']], $row);
    }

    public function testSortPutsCriticalFirstAndKeepsTheRegistryOrder(): void
    {
        $sorted = Rules::sort([
            ['status' => Rules::STATUS_OK, 'k' => 'a'],
            ['status' => Rules::STATUS_CRITICAL, 'k' => 'b'],
            ['status' => Rules::STATUS_INFO, 'k' => 'c'],
            ['status' => Rules::STATUS_OK, 'k' => 'd'],
            ['status' => Rules::STATUS_WARNING, 'k' => 'e'],
        ]);
        $this->assertSame(['b', 'e', 'a', 'd', 'c'], array_column($sorted, 'k'));
    }
}

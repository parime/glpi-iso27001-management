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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Perimeter;

use GlpiPlugin\Grcmanager\Services\Perimeter\PerimeterRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #536 : arbre fictif. Racine 0 ; groupe 1 ; filiales Nord (2) et Sud (3) sous le groupe ;
 * site Lille (4) sous Nord.
 */
final class PerimeterRulesTest extends TestCase
{
    private const PARENTS = [0 => -1, 1 => 0, 2 => 1, 3 => 1, 4 => 2];

    public function testChainGoesFromTheEntityUpToTheRoot(): void
    {
        $this->assertSame([4, 2, 1, 0], PerimeterRules::chain(4, self::PARENTS));
        $this->assertSame([0], PerimeterRules::chain(0, self::PARENTS));
        // Entité inconnue : elle seule.
        $this->assertSame([9], PerimeterRules::chain(9, self::PARENTS));
    }

    public function testChainStopsOnALoop(): void
    {
        $this->assertSame([5, 6], PerimeterRules::chain(5, [5 => 6, 6 => 5]));
    }

    public function testSoaEntityIsTheNearestEntityWithItsOwnSoa(): void
    {
        // Seule la racine tient une SoA : tout le monde la suit.
        $this->assertSame(0, PerimeterRules::soaEntity(PerimeterRules::chain(4, self::PARENTS), [0]));
        // Nord tient la sienne : Nord et Lille la suivent, Sud garde celle de la racine.
        $this->assertSame(2, PerimeterRules::soaEntity(PerimeterRules::chain(4, self::PARENTS), [0, 2]));
        $this->assertSame(2, PerimeterRules::soaEntity(PerimeterRules::chain(2, self::PARENTS), [0, 2]));
        $this->assertSame(0, PerimeterRules::soaEntity(PerimeterRules::chain(3, self::PARENTS), [0, 2]));
        // Aucune SoA trouvée dans la chaîne : la racine.
        $this->assertSame(0, PerimeterRules::soaEntity([7], []));
    }

    public function testSubtreeAndChildren(): void
    {
        $this->assertSame([1, 2, 3, 4], PerimeterRules::subtree(1, self::PARENTS));
        $this->assertSame([2, 4], PerimeterRules::subtree(2, self::PARENTS));
        $this->assertSame([3], PerimeterRules::subtree(3, self::PARENTS));
        $this->assertSame([2, 3], PerimeterRules::children(1, self::PARENTS));
        $this->assertSame([1], PerimeterRules::children(0, self::PARENTS));
        $this->assertSame([], PerimeterRules::children(4, self::PARENTS));
    }

    public function testRiskCountsKeepOnlyOpenRisksByLevel(): void
    {
        $counts = PerimeterRules::riskCounts([
            ['risk_level' => 'critical', 'status' => 'identified'],
            ['risk_level' => 'high', 'status' => 'in_treatment'],
            ['risk_level' => 'high', 'status' => 'closed'],
            ['risk_level' => 'low', 'status' => 'accepted'],
            ['risk_level' => 'unknown', 'status' => 'identified'],
        ]);

        $this->assertSame(
            ['low' => 0, 'medium' => 0, 'high' => 1, 'critical' => 1, 'open' => 3, 'total' => 5],
            $counts
        );
    }
}

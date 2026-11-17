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

    public function testAnItemIsReachedFromItsEntityOrFromBelowWhenRecursive(): void
    {
        // Profil limité à Nord (2) : un audit de Nord, oui ; un audit de Sud (3), non.
        $this->assertTrue(PerimeterRules::reaches([2], 2, false, self::PARENTS));
        $this->assertFalse(PerimeterRules::reaches([2], 3, false, self::PARENTS));
        // Un élément du groupe (1) ne descend vers Nord que s'il est visible des sous-entités.
        $this->assertFalse(PerimeterRules::reaches([2], 1, false, self::PARENTS));
        $this->assertTrue(PerimeterRules::reaches([2], 1, true, self::PARENTS));
        // Profil récursif sur le groupe : ses sous-entités sont dans la liste fournie.
        $this->assertTrue(PerimeterRules::reaches([1, 2, 3, 4], 4, false, self::PARENTS));
        $this->assertFalse(PerimeterRules::reaches([], 0, true, self::PARENTS));
    }

    public function testOpenNonconformitiesAreCountedBySeverity(): void
    {
        $counts = PerimeterRules::nonconformityCounts([
            ['severity' => 'major', 'status' => 'open', 'finding_type' => 'nonconformity'],
            ['severity' => 'critical', 'status' => 'in_progress', 'finding_type' => 'nonconformity'],
            ['severity' => 'minor', 'status' => 'closed', 'finding_type' => 'nonconformity'],
            // Une observation n'est pas une non-conformité, même ouverte.
            ['severity' => 'major', 'status' => 'open', 'finding_type' => 'observation'],
        ]);

        $this->assertSame(['minor' => 0, 'major' => 1, 'critical' => 1, 'open' => 2, 'total' => 4], $counts);
    }
}

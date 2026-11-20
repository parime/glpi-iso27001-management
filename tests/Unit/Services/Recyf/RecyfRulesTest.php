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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Recyf;

use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults as Catalog;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #599 : règles du registre ReCyF (catégorie, synthèse, ordre, création, entité du registre).
 */
final class RecyfRulesTest extends TestCase
{
    public function testCategoryIsNormalized(): void
    {
        self::assertSame('important', Rules::normalizeCategory('important'));
        self::assertSame('essential', Rules::normalizeCategory('essential'));
        self::assertSame('', Rules::normalizeCategory('critical'));
        self::assertSame('', Rules::normalizeCategory(['essential']));
    }

    public function testSummaryCountsApplicableDoneOverdueAndUnassigned(): void
    {
        $rows = [
            ['code' => '1.1', 'applicability' => 'yes', 'implementation_status' => 'implemented', 'users_id' => 2],
            ['code' => '1.2', 'applicability' => 'partial', 'implementation_status' => 'in_progress',
                'users_id' => 0, 'due_date' => '2026-01-01'],
            ['code' => '1.3', 'applicability' => 'no', 'implementation_status' => 'not_started', 'users_id' => 0],
            ['code' => '4.1', 'applicability' => 'yes', 'implementation_status' => 'verified', 'users_id' => 3,
                'due_date' => '2026-01-01'],
        ];
        $summary = Rules::summary($rows, '2026-11-20');
        self::assertSame(4, $summary['total']);
        self::assertSame(3, $summary['applicable']);
        self::assertSame(1, $summary['not_applicable']);
        self::assertSame(2, $summary['done']);
        self::assertSame(1, $summary['overdue'], 'Seule la mesure non faite et échue est en retard.');
        self::assertSame(1, $summary['unassigned'], 'Une mesure non applicable ne compte pas.');
        self::assertNotNull($summary['rate']);
        self::assertNull(Rules::summary([], '2026-11-20')['rate']);
    }

    public function testInCatalogKeepsTheCategoryMeasuresInOrder(): void
    {
        $rows = [
            ['code' => '16.1'], ['code' => '10.A.1'], ['code' => '2.A.2'], ['code' => '1.1'], ['code' => 'x'],
        ];
        self::assertSame(
            ['1.1', '10.A.1'],
            array_column(Rules::inCatalog($rows, Catalog::CATEGORY_IMPORTANT), 'code')
        );
        self::assertSame(
            ['1.1', '2.A.2', '10.A.1', '16.1'],
            array_column(Rules::inCatalog($rows, Catalog::CATEGORY_ESSENTIAL), 'code')
        );
        self::assertSame([], Rules::inCatalog($rows, ''));
    }

    public function testMissingCodesCompleteTheWholeCatalog(): void
    {
        self::assertCount(152, Rules::missingCodes([]));
        $missing = Rules::missingCodes(['1.1', '20.6']);
        self::assertCount(150, $missing);
        self::assertNotContains('1.1', $missing);
        self::assertSame([], Rules::missingCodes(Catalog::codes()));
    }

    public function testRegisterEntityIsTheClosestClassifiedOne(): void
    {
        $categories = [3 => 'essential', 1 => 'important', 9 => 'bogus'];
        self::assertSame(3, Rules::registerEntity([5, 3, 1, 0], $categories));
        self::assertSame(1, Rules::registerEntity([9, 1, 0], $categories));
        self::assertNull(Rules::registerEntity([9, 0], $categories));
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Acn;

use GlpiPlugin\Grcmanager\Services\Acn\AcnRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #703 : règles du registre NIS2 Italia - ACN.
 */
final class AcnRulesTest extends TestCase
{
    public function testNormalization(): void
    {
        self::assertSame('essential', Rules::normalizeCategory('essential'));
        self::assertSame('', Rules::normalizeCategory('critical'));
        self::assertSame('', Rules::normalizeCategory(['important']));
        self::assertSame('yes', Rules::normalizeApplicability('maybe'));
        self::assertSame('partial', Rules::normalizeApplicability('partial'));
        self::assertSame('not_started', Rules::normalizeStatus('done'));
        self::assertSame('verified', Rules::normalizeStatus('verified'));
    }

    public function testJustificationIsRequiredWhenNotFullyApplicable(): void
    {
        self::assertSame([], Rules::errors(['applicability' => 'yes']));
        self::assertSame([Rules::ERROR_JUSTIFICATION], Rules::errors(['applicability' => 'no', 'justification' => ' ']));
        self::assertSame([], Rules::errors(['applicability' => 'partial', 'justification' => 'Sito unico.']));
    }

    public function testSummaryCountsStatusesDeadlinesAndApprovals(): void
    {
        $rows = [
            // Approbation exigée (GV.RR-02, punto 1), enregistrée.
            ['code' => 'GV.RR-02', 'point' => 1, 'applicability' => 'yes', 'implementation_status' => 'implemented',
                'users_id' => 5, 'due_date' => '2026-01-01', 'approval_date' => '2026-02-01'],
            // Approbation exigée (ID.RA-05, punto 3), manquante ; échéance dépassée.
            ['code' => 'ID.RA-05', 'point' => 3, 'applicability' => 'yes', 'implementation_status' => 'in_progress',
                'users_id' => 0, 'due_date' => '2026-03-01', 'approval_date' => null],
            // Non applicable : hors taux, échéance et approbation ignorées.
            ['code' => 'PR.PS-06', 'point' => 1, 'applicability' => 'no', 'implementation_status' => 'not_started',
                'users_id' => 0, 'due_date' => '2026-01-01', 'approval_date' => null],
            ['code' => 'DE.CM-01', 'point' => 2, 'applicability' => 'partial', 'implementation_status' => 'not_started',
                'users_id' => 3, 'due_date' => null, 'approval_date' => null],
        ];
        $summary = Rules::summary($rows, '2026-06-01');

        self::assertSame(4, $summary['total']);
        self::assertSame(3, $summary['applicable']);
        self::assertSame(1, $summary['not_applicable']);
        self::assertSame(1, $summary['done']);
        self::assertSame(1, $summary['overdue']);
        self::assertSame(2, $summary['approvals_required']);
        self::assertSame(1, $summary['approvals_missing']);
        self::assertSame(1, $summary['unassigned']);
        // (1 + 0,5) / 3
        self::assertSame(50.0, $summary['rate']);
        self::assertNull(Rules::summary([], '2026-06-01')['rate']);
    }

    public function testCatalogFilterAndOrder(): void
    {
        $rows = [
            ['code' => 'RC.RP-01', 'point' => 1],
            ['code' => 'DE.CM-01', 'point' => 5],
            ['code' => 'GV.OC-04', 'point' => 1],
            ['code' => 'ID.AM-03', 'point' => 1],
        ];
        $important = array_map(static fn (array $r): string => $r['code'] . '#' . $r['point'], Rules::inCatalog($rows, 'important'));
        self::assertSame(['GV.OC-04#1', 'RC.RP-01#1'], $important);
        self::assertCount(4, Rules::inCatalog($rows, 'essential'));
        self::assertSame([], Rules::inCatalog($rows, ''));
    }

    public function testMissingPointsAreTheWholeCatalogMinusExisting(): void
    {
        self::assertCount(116, Rules::missingPoints([]));
        $missing = Rules::missingPoints([['GV.OC-04', 1], ['DE.CM-01', 7]]);
        self::assertCount(114, $missing);
        self::assertNotContains(['GV.OC-04', 1], $missing);
    }

    public function testRegisterEntityIsTheClosestClassifiedAncestor(): void
    {
        $categories = [0 => 'important', 12 => 'essential'];
        self::assertSame(12, Rules::registerEntity([15, 12, 0], $categories));
        self::assertSame(0, Rules::registerEntity([20, 0], $categories));
        self::assertNull(Rules::registerEntity([30, 31], ['31' => 'unknown']));
    }
}

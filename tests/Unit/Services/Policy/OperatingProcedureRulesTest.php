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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #775 : procédures d'exploitation (A.5.37), catégorie, domaines et couverture.
 */
final class OperatingProcedureRulesTest extends TestCase
{
    public function testUnknownCategoryFallsBackToPolicy(): void
    {
        self::assertSame(Rules::CATEGORY_PROCEDURE, Rules::sanitizeCategory('operating_procedure'));
        self::assertSame(Rules::CATEGORY_POLICY, Rules::sanitizeCategory('policy'));
        self::assertSame(Rules::CATEGORY_POLICY, Rules::sanitizeCategory('<b>x</b>'));
        self::assertSame(Rules::CATEGORY_POLICY, Rules::sanitizeCategory(null));
        self::assertSame(Rules::CATEGORY_POLICY, Rules::sanitizeCategory(['operating_procedure']));
    }

    public function testOnlyAProcedureHasADomain(): void
    {
        self::assertSame('backup', Rules::sanitizeDomain('backup', Rules::CATEGORY_PROCEDURE));
        self::assertSame('', Rules::sanitizeDomain('backup', Rules::CATEGORY_POLICY));
        self::assertSame('Impression x', Rules::sanitizeDomain("  <b>Impression</b>\n x ", Rules::CATEGORY_PROCEDURE));
        self::assertSame('', Rules::sanitizeDomain(['backup'], Rules::CATEGORY_PROCEDURE));
        self::assertSame(
            Rules::MAX_DOMAIN_LENGTH,
            mb_strlen(Rules::sanitizeDomain(str_repeat('é', 100), Rules::CATEGORY_PROCEDURE))
        );
    }

    public function testDomainListHasNoDuplicateNorEmptyEntry(): void
    {
        self::assertSame(
            ['backup', 'Téléphonie'],
            Rules::sanitizeDomains(['backup', '', ' ', 'BACKUP', 'Téléphonie', 'téléphonie', 42 => null])
        );
        self::assertCount(Rules::MAX_DOMAINS, Rules::sanitizeDomains(array_map('strval', range(1, 80))));
    }

    public function testFormKeepsDefaultOrderThenOtherDomains(): void
    {
        $domains = Rules::fromForm(['maintenance', 'backup', 'unknown'], "Téléphonie\r\n\r\nImpression\nbackup");

        self::assertSame(['backup', 'maintenance', 'Téléphonie', 'Impression'], $domains);
        self::assertSame(['Téléphonie', 'Impression'], Rules::customDomains($domains));
        self::assertSame([], Rules::fromForm([], ''));
    }

    public function testProcedureStatus(): void
    {
        $today = '2026-11-20';
        self::assertSame(Rules::STATUS_UP_TO_DATE, Rules::procedureStatus('approved', '2026-11-20', $today));
        self::assertSame(Rules::STATUS_REVIEW_DUE, Rules::procedureStatus('approved', '2026-11-19', $today));
        self::assertSame(Rules::STATUS_REVIEW_DUE, Rules::procedureStatus('approved', null, $today), 'revue jamais planifiée');
        self::assertSame(Rules::STATUS_NOT_APPROVED, Rules::procedureStatus('draft', '2027-01-01', $today));
        self::assertNull(Rules::procedureStatus('archived', '2027-01-01', $today));
    }

    public function testCoverageKeepsTheBestProcedureOfEachDomain(): void
    {
        $procedures = [
            ['procedure_domain' => 'backup', 'status' => 'draft', 'next_review_date' => null],
            ['procedure_domain' => 'backup', 'status' => 'approved', 'next_review_date' => '2027-01-01'],
            ['procedure_domain' => 'restore', 'status' => 'approved', 'next_review_date' => '2026-01-01'],
            ['procedure_domain' => 'monitoring', 'status' => 'draft', 'next_review_date' => null],
            ['procedure_domain' => 'logging', 'status' => 'archived', 'next_review_date' => '2027-01-01'],
            ['procedure_domain' => 'Téléphonie', 'status' => 'approved', 'next_review_date' => '2027-01-01'],
            ['procedure_domain' => 'not_expected', 'status' => 'approved', 'next_review_date' => '2027-01-01'],
        ];
        $coverage = Rules::coverage(['backup', 'restore', 'monitoring', 'logging', 'téléphonie'], $procedures, '2026-11-20');

        self::assertSame([
            ['domain' => 'backup', 'status' => Rules::STATUS_UP_TO_DATE, 'count' => 2],
            ['domain' => 'restore', 'status' => Rules::STATUS_REVIEW_DUE, 'count' => 1],
            ['domain' => 'monitoring', 'status' => Rules::STATUS_NOT_APPROVED, 'count' => 1],
            ['domain' => 'logging', 'status' => Rules::STATUS_MISSING, 'count' => 0],
            ['domain' => 'téléphonie', 'status' => Rules::STATUS_UP_TO_DATE, 'count' => 1],
        ], $coverage['domains']);
        self::assertSame([
            'total' => 5, 'up_to_date' => 2, 'review_due' => 1, 'not_approved' => 1, 'missing' => 1, 'rate' => 40,
        ], $coverage['summary']);
    }

    public function testEmptyCoverage(): void
    {
        $coverage = Rules::coverage([], [], '2026-11-20');

        self::assertSame([], $coverage['domains']);
        self::assertSame(0, $coverage['summary']['rate']);
    }
}

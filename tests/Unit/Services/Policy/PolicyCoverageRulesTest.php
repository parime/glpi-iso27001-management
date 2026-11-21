<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Policy\PolicyCoverageRules as Rules;
use PHPUnit\Framework\TestCase;

/** Issue #879 : couverture des mesures par les textes de la bibliothèque des politiques. */
final class PolicyCoverageRulesTest extends TestCase
{
    private const CODES = ['A.5.1', 'A.5.2', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.1', 'A.8.16'];

    public function testTextStateFollowsStatusAndReviewDate(): void
    {
        $today = '2026-06-15';
        self::assertSame(Rules::TEXT_APPROVED, Rules::textState(['status' => 'approved'], $today));
        self::assertSame(
            Rules::TEXT_APPROVED,
            Rules::textState(['status' => 'approved', 'next_review_date' => '2026-06-15'], $today)
        );
        self::assertSame(
            Rules::TEXT_REVIEW_DUE,
            Rules::textState(['status' => 'approved', 'next_review_date' => '2026-06-14'], $today)
        );
        self::assertSame(Rules::TEXT_NOT_APPROVED, Rules::textState(['status' => 'draft'], $today));
        self::assertSame(Rules::TEXT_NOT_APPROVED, Rules::textState(['status' => 'archived'], $today));
    }

    public function testTheBestTextDecidesTheControlStatus(): void
    {
        self::assertSame(Rules::STATUS_UNCOVERED, Rules::controlStatus([]));
        self::assertSame(Rules::STATUS_NOT_APPROVED, Rules::controlStatus([Rules::TEXT_NOT_APPROVED]));
        self::assertSame(
            Rules::STATUS_REVIEW_DUE,
            Rules::controlStatus([Rules::TEXT_NOT_APPROVED, Rules::TEXT_REVIEW_DUE])
        );
        self::assertSame(
            Rules::STATUS_COVERED,
            Rules::controlStatus([Rules::TEXT_REVIEW_DUE, Rules::TEXT_APPROVED, Rules::TEXT_NOT_APPROVED])
        );
        self::assertFalse(Rules::isGap(Rules::STATUS_COVERED));
        self::assertTrue(Rules::isGap(Rules::STATUS_REVIEW_DUE));
    }

    public function testSummaryCountsAndRate(): void
    {
        $summary = Rules::summarize([Rules::STATUS_COVERED, Rules::STATUS_COVERED, Rules::STATUS_UNCOVERED]);
        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['counts'][Rules::STATUS_COVERED]);
        self::assertSame(1, $summary['counts'][Rules::STATUS_UNCOVERED]);
        self::assertSame(0, $summary['counts'][Rules::STATUS_REVIEW_DUE]);
        self::assertSame(66, $summary['rate']);
        self::assertNull(Rules::summarize([])['rate']);
    }

    public function testCodesAcceptSingleCodesRangesAndThemes(): void
    {
        $parsed = Rules::parseCodes('a.5.1; A.5.15-A.5.17, 8', self::CODES);
        self::assertSame(['A.5.1', 'A.5.15', 'A.5.16', 'A.5.17', 'A.8.1', 'A.8.16'], $parsed['codes']);
        self::assertSame([], $parsed['unknown']);

        $short = Rules::parseCodes('A.5.18-15', self::CODES);
        self::assertSame(['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18'], $short['codes'], 'plage inversée et abrégée');
    }

    public function testUnknownCodesAreReportedNotGuessed(): void
    {
        $parsed = Rules::parseCodes('A.8.1 A.9.4 nonsense', self::CODES);
        self::assertSame(['A.8.1'], $parsed['codes'], 'A.8.1 ne vaut pas A.8.16');
        self::assertSame(['A.9.4', 'A.NONSENSE'], $parsed['unknown']);
        self::assertSame(['codes' => [], 'unknown' => []], Rules::parseCodes('  ', self::CODES));
    }

    public function testSectionAndFilterAreNormalized(): void
    {
        self::assertSame('4.2 Access control', Rules::normalizeSection("  4.2\n Access   control "));
        self::assertSame(Rules::SECTION_MAX, mb_strlen(Rules::normalizeSection(str_repeat('é', 300))));
        self::assertSame('', Rules::normalizeSection(null));
        self::assertSame(Rules::FILTER_GAPS, Rules::normalizeFilter('anything'));
        self::assertSame(Rules::FILTER_ALL, Rules::normalizeFilter('all'));
    }
}

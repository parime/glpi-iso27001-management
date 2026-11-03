<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Compliance;

use DateTimeImmutable;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComplianceObligationRulesTest extends TestCase
{
    #[DataProvider('allowedTypeProvider')]
    public function testNormalizeTypeKeepsAnAllowedValue(string $type): void
    {
        self::assertSame($type, ComplianceObligationRules::normalizeType($type));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedTypeProvider(): array
    {
        return [
            'legal'       => ['legal'],
            'regulatory'  => ['regulatory'],
            'contractual' => ['contractual'],
        ];
    }

    public function testNormalizeTypeFallsBackToLegalForAnUnknownValue(): void
    {
        self::assertSame('legal', ComplianceObligationRules::normalizeType('not_a_real_type'));
    }

    public function testNormalizeTypeFallsBackToLegalForNull(): void
    {
        self::assertSame('legal', ComplianceObligationRules::normalizeType(null));
    }

    #[DataProvider('allowedStatusProvider')]
    public function testNormalizeComplianceStatusKeepsAnAllowedValue(string $status): void
    {
        self::assertSame($status, ComplianceObligationRules::normalizeComplianceStatus($status));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedStatusProvider(): array
    {
        return [
            'compliant'           => ['compliant'],
            'partially_compliant' => ['partially_compliant'],
            'non_compliant'       => ['non_compliant'],
            'not_assessed'        => ['not_assessed'],
        ];
    }

    public function testNormalizeComplianceStatusFallsBackToNotAssessedForAnUnknownValue(): void
    {
        self::assertSame('not_assessed', ComplianceObligationRules::normalizeComplianceStatus('bogus'));
    }

    public function testNormalizeComplianceStatusFallsBackToNotAssessedForNull(): void
    {
        self::assertSame('not_assessed', ComplianceObligationRules::normalizeComplianceStatus(null));
    }

    // --- Optional zero-or-one link to a risk (issue #30) -------------------------------------

    public function testNormalizeLinkedRiskIdKeepsAPositiveId(): void
    {
        self::assertSame(42, ComplianceObligationRules::normalizeLinkedRiskId(42));
        self::assertSame(42, ComplianceObligationRules::normalizeLinkedRiskId('42'));
    }

    public function testNormalizeLinkedRiskIdCollapsesZeroToZero(): void
    {
        self::assertSame(0, ComplianceObligationRules::normalizeLinkedRiskId(0));
    }

    public function testNormalizeLinkedRiskIdCollapsesNullToZero(): void
    {
        self::assertSame(0, ComplianceObligationRules::normalizeLinkedRiskId(null));
    }

    public function testNormalizeLinkedRiskIdCollapsesNegativeToZero(): void
    {
        self::assertSame(0, ComplianceObligationRules::normalizeLinkedRiskId(-3));
    }

    public function testNormalizeLinkedRiskIdCollapsesNonNumericStringToZero(): void
    {
        self::assertSame(0, ComplianceObligationRules::normalizeLinkedRiskId('not-an-id'));
    }

    public function testUnlinkingIsSettingBackToZero(): void
    {
        // The "unlink" action a form submits is indistinguishable from "never linked": both
        // normalize to 0, the single canonical "no risk" value (see class docblock).
        $linked = ComplianceObligationRules::normalizeLinkedRiskId(7);
        self::assertTrue(ComplianceObligationRules::isLinkedToRisk($linked));

        $unlinked = ComplianceObligationRules::normalizeLinkedRiskId(0);
        self::assertFalse(ComplianceObligationRules::isLinkedToRisk($unlinked));
    }

    public function testIsLinkedToRiskIsFalseForZero(): void
    {
        self::assertFalse(ComplianceObligationRules::isLinkedToRisk(0));
    }

    public function testIsLinkedToRiskIsTrueForAPositiveId(): void
    {
        self::assertTrue(ComplianceObligationRules::isLinkedToRisk(1));
    }

    // --- Review-reminder due/not-due logic ----------------------------------------------------

    public function testReviewIsNotDueWithNoReviewDate(): void
    {
        self::assertFalse(ComplianceObligationRules::isReviewDue(null));
        self::assertFalse(ComplianceObligationRules::isReviewDue(''));
        self::assertFalse(ComplianceObligationRules::isReviewDue('   '));
    }

    public function testReviewIsNotDueWithAnUnparsableDate(): void
    {
        self::assertFalse(ComplianceObligationRules::isReviewDue('not-a-date'));
    }

    public function testReviewIsDueWhenTheDateHasAlreadyPassed(): void
    {
        $now = new DateTimeImmutable('2026-08-31');

        self::assertTrue(ComplianceObligationRules::isReviewDue('2026-01-01', $now));
    }

    public function testReviewIsDueExactlyOnTheWindowBoundary(): void
    {
        $now = new DateTimeImmutable('2026-08-31');
        $boundary = $now->modify('+' . ComplianceObligationRules::REMINDER_WINDOW_DAYS . ' days');

        self::assertTrue(ComplianceObligationRules::isReviewDue($boundary->format('Y-m-d'), $now));
    }

    public function testReviewIsNotDueJustPastTheWindowBoundary(): void
    {
        $now = new DateTimeImmutable('2026-08-31');
        $justPast = $now->modify('+' . (ComplianceObligationRules::REMINDER_WINDOW_DAYS + 1) . ' days');

        self::assertFalse(ComplianceObligationRules::isReviewDue($justPast->format('Y-m-d'), $now));
    }

    public function testReviewIsNotDueFarInTheFuture(): void
    {
        $now = new DateTimeImmutable('2026-08-31');

        self::assertFalse(ComplianceObligationRules::isReviewDue('2027-06-01', $now));
    }
}

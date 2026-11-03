<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use DateTimeImmutable;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreatmentPlanRulesTest extends TestCase
{
    // --- isTreatmentPlanRelevant() : only mitigate/transfer ----------------------------------

    public function testTreatmentPlanIsRelevantForMitigate(): void
    {
        self::assertTrue(TreatmentPlanRules::isTreatmentPlanRelevant('mitigate'));
    }

    public function testTreatmentPlanIsRelevantForTransfer(): void
    {
        self::assertTrue(TreatmentPlanRules::isTreatmentPlanRelevant('transfer'));
    }

    public function testTreatmentPlanIsNotRelevantForAccept(): void
    {
        self::assertFalse(TreatmentPlanRules::isTreatmentPlanRelevant('accept'));
    }

    public function testTreatmentPlanIsNotRelevantForAvoid(): void
    {
        self::assertFalse(TreatmentPlanRules::isTreatmentPlanRelevant('avoid'));
    }

    public function testTreatmentPlanIsNotRelevantForNoDecisionYet(): void
    {
        self::assertFalse(TreatmentPlanRules::isTreatmentPlanRelevant(''));
    }

    public function testTreatmentPlanIsNotRelevantForNull(): void
    {
        self::assertFalse(TreatmentPlanRules::isTreatmentPlanRelevant(null));
    }

    public function testTreatmentPlanIsNotRelevantForAnUnknownValue(): void
    {
        self::assertFalse(TreatmentPlanRules::isTreatmentPlanRelevant('not_a_real_treatment'));
    }

    // --- normalizeStatus() -------------------------------------------------------------------

    #[DataProvider('allowedStatusProvider')]
    public function testNormalizeStatusKeepsAnAllowedValue(string $status): void
    {
        self::assertSame($status, TreatmentPlanRules::normalizeStatus($status));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedStatusProvider(): array
    {
        return [
            'planned'     => ['planned'],
            'in_progress' => ['in_progress'],
            'done'        => ['done'],
        ];
    }

    public function testNormalizeStatusFallsBackToPlannedForAnUnknownValue(): void
    {
        self::assertSame('planned', TreatmentPlanRules::normalizeStatus('bogus'));
    }

    public function testNormalizeStatusFallsBackToPlannedForNull(): void
    {
        self::assertSame('planned', TreatmentPlanRules::normalizeStatus(null));
    }

    public function testNormalizeStatusFallsBackToPlannedForEmptyString(): void
    {
        self::assertSame('planned', TreatmentPlanRules::normalizeStatus(''));
    }

    // --- isOverdue() ---------------------------------------------------------------------------

    public function testIsOverdueWithNoDueDate(): void
    {
        self::assertFalse(TreatmentPlanRules::isOverdue(null, 'planned'));
        self::assertFalse(TreatmentPlanRules::isOverdue('', 'planned'));
        self::assertFalse(TreatmentPlanRules::isOverdue('   ', 'planned'));
    }

    public function testIsOverdueWithAnUnparsableDate(): void
    {
        self::assertFalse(TreatmentPlanRules::isOverdue('not-a-date', 'planned'));
    }

    public function testIsOverdueWhenDueDateHasAlreadyPassedAndNotDone(): void
    {
        $now = new DateTimeImmutable('2026-08-31');

        self::assertTrue(TreatmentPlanRules::isOverdue('2026-01-01', 'planned', $now));
        self::assertTrue(TreatmentPlanRules::isOverdue('2026-01-01', 'in_progress', $now));
    }

    public function testIsNotOverdueWhenDueDateIsToday(): void
    {
        // Strictly before today, same "due_date < today" boundary as
        // GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService's own $DB->find() criteria.
        $now = new DateTimeImmutable('2026-08-31');

        self::assertFalse(TreatmentPlanRules::isOverdue('2026-08-31', 'planned', $now));
    }

    public function testIsNotOverdueWhenDueDateIsInTheFuture(): void
    {
        $now = new DateTimeImmutable('2026-08-31');

        self::assertFalse(TreatmentPlanRules::isOverdue('2027-01-01', 'planned', $now));
    }

    public function testIsNeverOverdueOnceMarkedDoneRegardlessOfDueDate(): void
    {
        $now = new DateTimeImmutable('2026-08-31');

        self::assertFalse(TreatmentPlanRules::isOverdue('2020-01-01', 'done', $now));
    }
}

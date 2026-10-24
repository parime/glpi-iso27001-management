<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Review;

use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;
use PHPUnit\Framework\TestCase;

final class PeriodicReviewCatalogTest extends TestCase
{
    public function testEveryReviewHasAProposedFrequencyAndControls(): void
    {
        foreach (PeriodicReviewCatalog::keys() as $key) {
            self::assertContains(PeriodicReviewCatalog::defaultMonths($key), PeriodicReviewCatalog::FREQUENCIES, $key);
            self::assertNotSame([], PeriodicReviewCatalog::controls($key), $key);
        }
        self::assertTrue(PeriodicReviewCatalog::isKey('access_rights'));
        self::assertFalse(PeriodicReviewCatalog::isKey('inconnue'));
    }

    public function testFrequencyIsBroughtBackToAProposedValue(): void
    {
        self::assertSame(6, PeriodicReviewCatalog::normalizeMonths(6));
        self::assertSame(12, PeriodicReviewCatalog::normalizeMonths(0));
        self::assertSame(12, PeriodicReviewCatalog::normalizeMonths(11));
        self::assertSame(24, PeriodicReviewCatalog::normalizeMonths(240));
        self::assertSame(12, PeriodicReviewCatalog::normalizeMonths(-3));
        self::assertSame(1, PeriodicReviewCatalog::normalizeMonths(2));
    }

    public function testPeriodicityUsesTheRecurrentTicketFormat(): void
    {
        self::assertSame('1MONTH', PeriodicReviewCatalog::periodicity(1));
        self::assertSame('6MONTH', PeriodicReviewCatalog::periodicity(6));
        self::assertSame('1YEAR', PeriodicReviewCatalog::periodicity(12));
        self::assertSame('2YEAR', PeriodicReviewCatalog::periodicity(24));
    }

    public function testOverdueAfterOnePeriodWithoutReview(): void
    {
        self::assertNull(PeriodicReviewCatalog::nextDueDate(null, 6));
        self::assertSame('2027-04-15', PeriodicReviewCatalog::nextDueDate('2026-10-15', 6));

        // Jamais faite : en retard une période après l'activation.
        self::assertFalse(PeriodicReviewCatalog::isOverdue(null, 3, '2026-08-01', '2026-10-24'));
        self::assertTrue(PeriodicReviewCatalog::isOverdue(null, 3, '2026-07-01', '2026-10-24'));
        // Faite : en retard une fois l'échéance dépassée.
        self::assertFalse(PeriodicReviewCatalog::isOverdue('2026-05-01', 6, '2025-01-01', '2026-10-24'));
        self::assertTrue(PeriodicReviewCatalog::isOverdue('2026-04-01', 6, '2025-01-01', '2026-10-24'));
    }
}

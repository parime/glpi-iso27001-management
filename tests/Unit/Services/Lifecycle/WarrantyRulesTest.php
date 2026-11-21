<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Lifecycle;

use GlpiPlugin\Grcmanager\Services\Lifecycle\WarrantyRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #782 : état de la garantie d'un matériel d'après ses informations financières.
 */
final class WarrantyRulesTest extends TestCase
{
    private const TODAY = '2026-11-20';

    public function testEndDateAddsMonthsLikeGlpi(): void
    {
        self::assertSame('2029-03-15', R::endDate('2026-03-15', 36));
        self::assertSame('2027-03-03', R::endDate('2027-01-31', 1), 'Même date que la fiche de GLPI (DateInterval).');
        self::assertSame('2026-03-15', R::endDate('2026-03-15', 0));
    }

    public function testExpiredOnItsEndDateSoonWithinThresholdCoveredAfter(): void
    {
        $expired = R::evaluate(true, '2023-11-20', 36, self::TODAY, 90);
        self::assertSame(R::STATUS_EXPIRED, $expired['status'], 'Expirée le jour de sa date de fin.');
        self::assertSame('2026-11-20', $expired['end']);
        self::assertSame(0, $expired['days']);

        self::assertSame(R::STATUS_EXPIRED, R::evaluate(true, '2020-01-01', 12, self::TODAY, 90)['status']);

        $soon = R::evaluate(true, '2024-02-18', 36, self::TODAY, 90);
        self::assertSame('2027-02-18', $soon['end']);
        self::assertSame(90, $soon['days']);
        self::assertSame(R::STATUS_SOON, $soon['status'], 'Seuil inclus.');

        $covered = R::evaluate(true, '2024-02-19', 36, self::TODAY, 90);
        self::assertSame(R::STATUS_COVERED, $covered['status']);
        self::assertSame(91, $covered['days']);
        self::assertSame(R::STATUS_SOON, R::evaluate(true, '2024-02-19', 36, self::TODAY, 91)['status']);
    }

    public function testLifetimeIsCoveredAndMissingDataIsUnknown(): void
    {
        $lifetime = R::evaluate(true, null, R::LIFETIME, self::TODAY, 90);
        self::assertSame(R::STATUS_COVERED, $lifetime['status']);
        self::assertTrue($lifetime['lifetime']);
        self::assertSame('', $lifetime['end']);

        self::assertSame(R::REASON_NO_INFOCOM, R::evaluate(false, '2026-01-01', 36, self::TODAY, 90)['reason']);
        self::assertSame(R::REASON_NO_DATE, R::evaluate(true, null, 36, self::TODAY, 90)['reason']);
        self::assertSame(R::REASON_NO_DATE, R::evaluate(true, '0000-00-00', 36, self::TODAY, 90)['reason']);
        self::assertSame(R::REASON_NO_DATE, R::evaluate(true, '2026-02-30', 36, self::TODAY, 90)['reason']);
        self::assertSame(R::REASON_NO_DURATION, R::evaluate(true, '2026-01-01', 0, self::TODAY, 90)['reason']);
        foreach ([[false, '2026-01-01', 12], [true, '', 12], [true, '2026-01-01', 0]] as [$has, $start, $months]) {
            $warranty = R::evaluate($has, $start, $months, self::TODAY, 90);
            self::assertSame(R::STATUS_UNKNOWN, $warranty['status'], 'Jamais comptée comme sous garantie.');
            self::assertNull($warranty['days']);
        }
    }

    public function testCrossTabByTypeAndEntityAndCounts(): void
    {
        $assets = [
            ['itemtype' => 'Monitor', 'entities_id' => 2, 'status' => R::STATUS_EXPIRED],
            ['itemtype' => 'Computer', 'entities_id' => 3, 'status' => R::STATUS_COVERED],
            ['itemtype' => 'Computer', 'entities_id' => 2, 'status' => R::STATUS_EXPIRED],
            ['itemtype' => 'Computer', 'entities_id' => 2, 'status' => R::STATUS_SOON],
            ['itemtype' => 'Computer', 'entities_id' => 2, 'status' => 'bogus'],
        ];
        $groups = R::crossTab($assets);
        self::assertSame(
            [['Computer', 2], ['Computer', 3], ['Monitor', 2]],
            array_map(static fn (array $g): array => [$g['itemtype'], $g['entities_id']], $groups)
        );
        self::assertSame(
            [R::STATUS_EXPIRED => 1, R::STATUS_SOON => 1, R::STATUS_COVERED => 0, R::STATUS_UNKNOWN => 1],
            $groups[0]['counts']
        );
        self::assertSame(3, $groups[0]['total']);
        self::assertSame(
            [R::STATUS_EXPIRED => 2, R::STATUS_SOON => 1, R::STATUS_COVERED => 1, R::STATUS_UNKNOWN => 1],
            R::counts($assets)
        );
        self::assertSame([], R::crossTab([]));
    }

    public function testSeverityOrder(): void
    {
        self::assertLessThan(R::severity(R::STATUS_SOON), R::severity(R::STATUS_EXPIRED));
        self::assertLessThan(R::severity(R::STATUS_UNKNOWN), R::severity(R::STATUS_COVERED));
        self::assertSame(count(R::STATUSES), R::severity('bogus'));
    }

    public function testManufacturerEndOfLifeUsesTheCommonThreshold(): void
    {
        // TODAY = 2026-11-20 : atteinte le jour même, proche sous 90 jours, à venir au-delà.
        self::assertSame(R::EOL_REACHED, R::endOfLife('2025-06-30', self::TODAY, 90)['status']);
        self::assertSame(R::EOL_REACHED, R::endOfLife(self::TODAY, self::TODAY, 90)['status']);
        $soon = R::endOfLife('2027-01-15', self::TODAY, 90);
        self::assertSame(R::EOL_SOON, $soon['status']);
        self::assertSame(56, $soon['days']);
        self::assertSame(R::EOL_FUTURE, R::endOfLife('2027-01-15', self::TODAY, 30)['status']);
        foreach ([null, '', '0000-00-00', 'demain', '2027-02-30'] as $date) {
            $eol = R::endOfLife($date, self::TODAY, 90);
            self::assertSame(R::EOL_NONE, $eol['status']);
            self::assertSame('', $eol['date']);
            self::assertNull($eol['days']);
        }
        self::assertSame(
            [R::EOL_REACHED => 1, R::EOL_SOON => 1, R::EOL_FUTURE => 0, R::EOL_NONE => 2],
            R::endOfLifeCounts([
                ['eol' => ['status' => R::EOL_REACHED]],
                ['eol' => ['status' => R::EOL_SOON]],
                ['eol' => ['status' => R::EOL_NONE]],
                ['eol' => ['status' => 'bogus']],
            ])
        );
    }
}

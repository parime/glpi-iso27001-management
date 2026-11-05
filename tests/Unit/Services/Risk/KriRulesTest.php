<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use PHPUnit\Framework\TestCase;

final class KriRulesTest extends TestCase
{
    public function testHigherIsWorse(): void
    {
        $status = static fn (?float $value): string => KriRules::status($value, KriRules::HIGHER_IS_WORSE, 5.0, 10.0);
        self::assertSame(KriRules::GREEN, $status(4.9));
        self::assertSame(KriRules::ORANGE, $status(5.0));
        self::assertSame(KriRules::ORANGE, $status(9.99));
        self::assertSame(KriRules::RED, $status(10.0));
        self::assertSame(KriRules::NONE, $status(null));
    }

    public function testLowerIsWorse(): void
    {
        $status = static fn (float $value): string => KriRules::status($value, KriRules::LOWER_IS_WORSE, 95.0, 80.0);
        self::assertSame(KriRules::GREEN, $status(99.0));
        self::assertSame(KriRules::ORANGE, $status(95.0));
        self::assertSame(KriRules::RED, $status(80.0));
        self::assertSame(KriRules::RED, $status(10.0));
    }

    public function testSingleThresholdAndNoThreshold(): void
    {
        self::assertSame(KriRules::RED, KriRules::status(3.0, KriRules::HIGHER_IS_WORSE, null, 1.0));
        self::assertSame(KriRules::GREEN, KriRules::status(0.0, KriRules::HIGHER_IS_WORSE, null, 1.0));
        self::assertSame(KriRules::NONE, KriRules::status(3.0, KriRules::HIGHER_IS_WORSE, null, null));
    }

    public function testThresholdsMustFollowTheDirection(): void
    {
        self::assertTrue(KriRules::areThresholdsValid(KriRules::HIGHER_IS_WORSE, 5.0, 10.0));
        self::assertFalse(KriRules::areThresholdsValid(KriRules::HIGHER_IS_WORSE, 10.0, 5.0));
        self::assertTrue(KriRules::areThresholdsValid(KriRules::LOWER_IS_WORSE, 95.0, 80.0));
        self::assertFalse(KriRules::areThresholdsValid(KriRules::LOWER_IS_WORSE, 80.0, 95.0));
        self::assertTrue(KriRules::areThresholdsValid(KriRules::LOWER_IS_WORSE, null, 95.0));
    }

    public function testTrend(): void
    {
        self::assertNull(KriRules::trend([]));
        self::assertNull(KriRules::trend([3.0]));
        self::assertSame(KriRules::TREND_UP, KriRules::trend([1.0, 2.0, 5.0]));
        self::assertSame(KriRules::TREND_DOWN, KriRules::trend([5.0, 2.0]));
        self::assertSame(KriRules::TREND_STABLE, KriRules::trend([2.0, 2.0]));
        self::assertTrue(KriRules::isWorsening(KriRules::TREND_UP, KriRules::HIGHER_IS_WORSE));
        self::assertFalse(KriRules::isWorsening(KriRules::TREND_UP, KriRules::LOWER_IS_WORSE));
        self::assertTrue(KriRules::isWorsening(KriRules::TREND_DOWN, KriRules::LOWER_IS_WORSE));
        self::assertFalse(KriRules::isWorsening(KriRules::TREND_STABLE, KriRules::HIGHER_IS_WORSE));
    }

    public function testRate(): void
    {
        self::assertSame(25.0, KriRules::rate(1, 4));
        self::assertSame(33.3, KriRules::rate(1, 3));
        self::assertNull(KriRules::rate(0, 0));
    }

    public function testAlertLevelIsAClosedSetting(): void
    {
        self::assertSame(KriRules::RED, KriRules::normalizeAlertLevel('red'));
        self::assertSame(KriRules::ORANGE, KriRules::normalizeAlertLevel('orange'));
        self::assertSame(KriRules::RED, KriRules::normalizeAlertLevel('green'));
        self::assertSame(KriRules::RED, KriRules::normalizeAlertLevel(null));
        self::assertTrue(KriRules::isAlert(KriRules::RED, KriRules::RED));
        self::assertFalse(KriRules::isAlert(KriRules::ORANGE, KriRules::RED));
        self::assertTrue(KriRules::isAlert(KriRules::ORANGE, KriRules::ORANGE));
        self::assertFalse(KriRules::isAlert(KriRules::NONE, KriRules::ORANGE));
    }

    public function testAlertOncePerStateChangeAtRedLevel(): void
    {
        $level = KriRules::RED;
        self::assertSame(['event' => 'alert', 'notified' => 'red'], KriRules::alertTransition('', 'red', $level));
        self::assertSame(['event' => null, 'notified' => 'red'], KriRules::alertTransition('red', 'red', $level));
        self::assertSame(['event' => 'cleared', 'notified' => ''], KriRules::alertTransition('red', 'green', $level));
        self::assertSame(['event' => null, 'notified' => ''], KriRules::alertTransition('', 'green', $level));
        // Orange au niveau rouge : pas d'alerte, mais l'alerte est réarmée sans message.
        self::assertSame(['event' => null, 'notified' => ''], KriRules::alertTransition('', 'orange', $level));
        self::assertSame(['event' => null, 'notified' => ''], KriRules::alertTransition('red', 'orange', $level));
        self::assertSame(['event' => null, 'notified' => ''], KriRules::alertTransition('red', 'none', $level));
    }

    public function testAlertFromOrange(): void
    {
        $level = KriRules::ORANGE;
        self::assertSame(
            ['event' => 'alert', 'notified' => 'orange'],
            KriRules::alertTransition('', 'orange', $level)
        );
        self::assertSame(['event' => 'alert', 'notified' => 'red'], KriRules::alertTransition('orange', 'red', $level));
        // Amélioration qui reste en alerte : rien, puis une nouvelle alerte au retour au rouge.
        self::assertSame(['event' => null, 'notified' => 'orange'], KriRules::alertTransition('red', 'orange', $level));
        self::assertSame(['event' => 'cleared', 'notified' => ''], KriRules::alertTransition('orange', 'green', $level));
    }

    public function testMonthlyStatusCountsUseTheLastValueOfEachMonth(): void
    {
        $values = [
            1 => [
                ['date' => '2026-01-10', 'status' => 'green'],
                ['date' => '2026-02-03', 'status' => 'red'],
                ['date' => '2026-02-28', 'status' => 'orange'],
            ],
            2 => [
                ['date' => '2026-02-01', 'status' => 'red'],
                ['date' => '2026-04-15', 'status' => 'green'],
            ],
        ];
        $counts = KriRules::monthlyStatusCounts($values, ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04']);
        self::assertSame(['red' => 0, 'orange' => 0], $counts['2025-12']);
        self::assertSame(['red' => 0, 'orange' => 0], $counts['2026-01']);
        self::assertSame(['red' => 1, 'orange' => 1], $counts['2026-02']);
        self::assertSame(['red' => 1, 'orange' => 1], $counts['2026-03']);
        self::assertSame(['red' => 0, 'orange' => 1], $counts['2026-04']);
    }

    public function testLastMonths(): void
    {
        self::assertSame(['2025-12', '2026-01', '2026-02'], KriRules::lastMonths('2026-02-15', 3));
        self::assertCount(12, KriRules::lastMonths('2026-03-31'));
        self::assertSame('2026-03', KriRules::lastMonths('2026-03-31')[11]);
    }
}

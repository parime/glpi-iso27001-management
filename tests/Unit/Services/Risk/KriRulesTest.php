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
}

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\EbiosRules;
use PHPUnit\Framework\TestCase;

final class EbiosRulesTest extends TestCase
{
    public function testLevelIsKeptBetweenOneAndFour(): void
    {
        self::assertSame(1, EbiosRules::level('0'));
        self::assertSame(3, EbiosRules::level('3'));
        self::assertSame(4, EbiosRules::level(9));
    }

    public function testSourceRelevance(): void
    {
        self::assertSame('low', EbiosRules::sourceRelevance(1, 2, 2));
        self::assertSame('medium', EbiosRules::sourceRelevance(2, 2, 3));
        self::assertSame('high', EbiosRules::sourceRelevance(4, 3, 3));
    }

    public function testThreatLevelAndZone(): void
    {
        // Prestataire d'infogérance : très dépendant, très pénétrant, peu mature, confiance moyenne.
        $threat = EbiosRules::threatLevel(4, 4, 2, 2);
        self::assertSame(4.0, $threat);
        self::assertSame('danger', EbiosRules::threatZone($threat));

        self::assertSame(1.0, EbiosRules::threatLevel(2, 2, 2, 2));
        self::assertSame('control', EbiosRules::threatZone(1.0));

        self::assertSame(0.06, EbiosRules::threatLevel(1, 1, 4, 4));
        self::assertSame('watch', EbiosRules::threatZone(0.06));
        self::assertSame('danger', EbiosRules::threatZone(EbiosRules::DANGER_THRESHOLD));
    }

    public function testScalesMapToTheRiskRegister(): void
    {
        self::assertSame('low', EbiosRules::severityToImpact(1));
        self::assertSame('critical', EbiosRules::severityToImpact(4));
        self::assertSame('rare', EbiosRules::likelihoodToProbability(1));
        self::assertSame('certain', EbiosRules::likelihoodToProbability(4));
    }

    public function testProgressPointsToTheFirstEmptyWorkshop(): void
    {
        self::assertSame(['started' => 0, 'total' => 5, 'next' => 1], EbiosRules::progress([]));
        self::assertSame(
            ['started' => 3, 'total' => 5, 'next' => 3],
            EbiosRules::progress([1 => 4, 2 => 1, 3 => 0, 4 => 2])
        );
        self::assertNull(EbiosRules::progress([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1])['next']);
    }
}

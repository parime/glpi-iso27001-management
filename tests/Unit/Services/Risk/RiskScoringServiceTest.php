<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskScoringServiceTest extends TestCase
{
    private RiskScoringService $service;

    protected function setUp(): void
    {
        $this->service = new RiskScoringService();
    }

    public function testRareAndLowScoresToLow(): void
    {
        self::assertSame(1.0, $this->service->score('rare', 'low'));
        self::assertSame('low', $this->service->level('rare', 'low'));
    }

    public function testCertainAndCriticalScoresToCritical(): void
    {
        self::assertSame(16.0, $this->service->score('certain', 'critical'));
        self::assertSame('critical', $this->service->level('certain', 'critical'));
    }

    public function testPossibleAndMediumScoresToMedium(): void
    {
        self::assertSame(4.0, $this->service->score('possible', 'medium'));
        self::assertSame('medium', $this->service->level('possible', 'medium'));
    }

    public function testProbableAndHighScoresToHigh(): void
    {
        self::assertSame(9.0, $this->service->score('probable', 'high'));
        self::assertSame('high', $this->service->level('probable', 'high'));
    }

    public function testUnknownValuesScoreToZeroAndLow(): void
    {
        self::assertSame(0.0, $this->service->score('unknown', 'unknown'));
        self::assertSame('low', $this->service->level('unknown', 'unknown'));
    }

    /**
     * Default constructor uses RiskMatrixDefaults::MATRIX (Sprint 1's original grid, preserved
     * exactly so an existing install sees no change until an admin edits the matrix), verified
     * exhaustively here for every probability x impact combination.
     */
    #[DataProvider('defaultMatrixProvider')]
    public function testDefaultMatrixMatchesSprint1Grid(string $probability, string $impact, string $expected): void
    {
        self::assertSame($expected, $this->service->level($probability, $impact));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function defaultMatrixProvider(): array
    {
        $cases = [];
        foreach (RiskMatrixDefaults::MATRIX as $probability => $impacts) {
            foreach ($impacts as $impact => $level) {
                $cases["$probability x $impact"] = [$probability, $impact, $level];
            }
        }

        return $cases;
    }

    public function testInjectedMatrixOverridesDefault(): void
    {
        $customMatrix = [
            'rare'     => ['low' => 'critical', 'medium' => 'critical', 'high' => 'critical', 'critical' => 'critical'],
            'possible' => ['low' => 'critical', 'medium' => 'critical', 'high' => 'critical', 'critical' => 'critical'],
            'probable' => ['low' => 'critical', 'medium' => 'critical', 'high' => 'critical', 'critical' => 'critical'],
            'certain'  => ['low' => 'critical', 'medium' => 'critical', 'high' => 'critical', 'critical' => 'critical'],
        ];

        $service = new RiskScoringService($customMatrix);

        self::assertSame('critical', $service->level('rare', 'low'));
    }

    public function testMissingCombinationFallsBackToScoreThresholds(): void
    {
        // Incomplete matrix (missing the 'possible' row entirely): the fallback should still
        // compute a sensible level from the underlying score rather than erroring out.
        $incompleteMatrix = [
            'rare' => ['low' => 'low', 'medium' => 'low', 'high' => 'low', 'critical' => 'medium'],
        ];

        $service = new RiskScoringService($incompleteMatrix);

        // possible(2) x high(3) = 6 -> medium, per the score-based fallback thresholds.
        self::assertSame('medium', $service->level('possible', 'high'));
    }
}

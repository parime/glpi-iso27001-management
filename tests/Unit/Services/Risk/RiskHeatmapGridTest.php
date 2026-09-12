<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskHeatmapGrid;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use PHPUnit\Framework\TestCase;

final class RiskHeatmapGridTest extends TestCase
{
    public function testEveryCellOfTheDefaultMatrixIsPresentWithZeroCountWhenNoRisksExist(): void
    {
        $grid = RiskHeatmapGrid::build(RiskMatrixDefaults::MATRIX, []);

        foreach (RiskMatrixDefaults::MATRIX as $probability => $impacts) {
            foreach ($impacts as $impact => $level) {
                self::assertSame($level, $grid[$probability][$impact]['level']);
                self::assertSame(0, $grid[$probability][$impact]['count']);
            }
        }
    }

    public function testCountsAreOverlaidOntoTheMatchingCell(): void
    {
        $counts = [
            'possible' => ['high' => 5],
            'certain'  => ['critical' => 2],
        ];

        $grid = RiskHeatmapGrid::build(RiskMatrixDefaults::MATRIX, $counts);

        self::assertSame(5, $grid['possible']['high']['count']);
        self::assertSame(2, $grid['certain']['critical']['count']);
        // The level itself always comes from the matrix, never from the counts array.
        self::assertSame('medium', $grid['possible']['high']['level']);
        self::assertSame('critical', $grid['certain']['critical']['level']);
    }

    public function testCellsAbsentFromCountsDefaultToZeroRatherThanBeingOmitted(): void
    {
        $grid = RiskHeatmapGrid::build(RiskMatrixDefaults::MATRIX, ['rare' => ['low' => 3]]);

        // Untouched cell on the same row as a counted one.
        self::assertSame(0, $grid['rare']['medium']['count']);
        // Untouched row entirely.
        self::assertSame(0, $grid['certain']['critical']['count']);
    }

    public function testACustomMatrixWithFewerCellsOnlyProducesThoseCells(): void
    {
        $customMatrix = [
            'rare' => ['low' => 'low', 'medium' => 'low'],
        ];

        $grid = RiskHeatmapGrid::build($customMatrix, ['rare' => ['medium' => 7]]);

        self::assertSame(['low', 'medium'], array_keys($grid['rare']));
        self::assertSame(7, $grid['rare']['medium']['count']);
        self::assertArrayNotHasKey('possible', $grid);
    }

    public function testCountsForACombinationMissingFromTheMatrixAreIgnored(): void
    {
        // A count for a probability/impact pair that isn't even a key of the matrix (e.g. a stale
        // value left over after an admin edit removed a row/column) must never surface as a phantom
        // cell: the grid is driven by the matrix's own keys, counts are only ever looked up, never
        // iterated to invent new cells.
        $customMatrix = ['rare' => ['low' => 'low']];

        $grid = RiskHeatmapGrid::build($customMatrix, ['possible' => ['critical' => 9]]);

        self::assertSame(['rare'], array_keys($grid));
        self::assertArrayNotHasKey('possible', $grid);
    }
}

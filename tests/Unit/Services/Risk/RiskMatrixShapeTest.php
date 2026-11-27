<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterCsv;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScaleMigration;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1163 : nombre de niveaux de probabilité et d'impact réglables indépendamment (3 à 5),
 * sans changer le comportement des matrices 4x4 et 5x5 existantes ; score résiduel.
 */
final class RiskMatrixShapeTest extends TestCase
{
    public function testShapeCodesKeepTheHistoricalSquareSizes(): void
    {
        self::assertSame(4, RiskScale::shape(4, 4));
        self::assertSame(5, RiskScale::shape(5, 5));
        self::assertSame(3, RiskScale::shape(3, 3));
        self::assertSame(35, RiskScale::shape(3, 5));
        self::assertSame(53, RiskScale::shape(5, 3));
        self::assertSame(4, RiskScale::normalizeSize(44));
        self::assertSame(RiskScale::DEFAULT_SIZE, RiskScale::shape(2, 6));
    }

    public function testEachAxisHasItsOwnNumberOfLevels(): void
    {
        foreach ([3, 4, 5] as $probability) {
            foreach ([3, 4, 5] as $impact) {
                $size = RiskScale::shape($probability, $impact);
                self::assertContains($size, RiskScale::SIZES);
                self::assertCount($probability, RiskScale::probabilityKeys($size));
                self::assertCount($impact, RiskScale::impactKeys($size));
                self::assertSame($probability, RiskScale::probabilityLevels($size));
                self::assertSame($impact, RiskScale::impactLevels($size));
            }
        }
        self::assertSame(['rare', 'possible', 'probable'], RiskScale::probabilityKeys(35));
        self::assertSame(['negligible', 'low', 'medium', 'high', 'critical'], RiskScale::impactKeys(35));
    }

    public function testExistingSquareGridsAndThresholdsAreUnchanged(): void
    {
        self::assertSame(RiskMatrixDefaults::MATRIX, RiskMatrixDefaults::forSize(4));
        self::assertSame(RiskMatrixDefaults::MATRIX_5, RiskMatrixDefaults::forSize(5));
        self::assertSame(['medium' => 4, 'high' => 8, 'critical' => 12], RiskScale::thresholds(4));
        self::assertSame(['medium' => 5, 'high' => 10, 'critical' => 16], RiskScale::thresholds(5));
    }

    public function testRectangularGridIsCompleteAndIncreasesAlongBothAxes(): void
    {
        // 3 x 5 : score maximal 15, seuils au quart, à la moitié et aux trois quarts.
        self::assertSame(['medium' => 4, 'high' => 8, 'critical' => 12], RiskScale::thresholds(35));
        $matrix = RiskMatrixDefaults::forSize(35);
        self::assertSame(RiskScale::probabilityKeys(35), array_keys($matrix));
        self::assertSame('low', $matrix['rare']['negligible']);
        self::assertSame('critical', $matrix['probable']['critical']);
        $rank = array_flip(RiskScale::LEVELS);
        $probabilities = RiskScale::probabilityKeys(35);
        $impacts = RiskScale::impactKeys(35);
        foreach ($probabilities as $p => $probability) {
            self::assertSame($impacts, array_keys($matrix[$probability]));
            foreach ($impacts as $i => $impact) {
                $current = $rank[$matrix[$probability][$impact]];
                if ($i > 0) {
                    self::assertGreaterThanOrEqual($rank[$matrix[$probability][$impacts[$i - 1]]], $current);
                }
                if ($p > 0) {
                    self::assertGreaterThanOrEqual($rank[$matrix[$probabilities[$p - 1]][$impact]], $current);
                }
            }
        }
    }

    public function testScoreUsesTheRankOnEachAxis(): void
    {
        $scoring = new RiskScoringService(RiskMatrixDefaults::forSize(35), 35);
        self::assertSame(15.0, $scoring->score('probable', 'critical'));
        self::assertSame(1.0, $scoring->score('rare', 'negligible'));
        self::assertSame(6.0, $scoring->score('possible', 'medium'));
    }

    public function testProposedMappingFromFourByFourKeepsTheOrderOfEachAxis(): void
    {
        $mapping = RiskScale::proposedMapping(4, 35);
        self::assertSame(['rare', 'possible', 'probable', 'certain'], array_keys($mapping['probability']));
        $axes = ['probability' => RiskScale::probabilityKeys(35), 'impact' => RiskScale::impactKeys(35)];
        foreach ($axes as $axis => $keys) {
            $previous = -1;
            foreach ($mapping[$axis] as $target) {
                $index = array_search($target, $keys, true);
                self::assertNotFalse($index);
                self::assertGreaterThanOrEqual($previous, $index);
                $previous = $index;
            }
        }
        // Réduction de 4 à 3 niveaux : chaque nouveau niveau reçoit au moins une ancienne valeur.
        self::assertSame(RiskScale::probabilityKeys(35), array_values(array_unique($mapping['probability'])));
    }

    public function testFoldBringsAnyKnownKeyIntoTheScale(): void
    {
        self::assertSame('probable', RiskScale::fold('probability', 'certain', 35));
        self::assertSame('rare', RiskScale::fold('probability', 'very_rare', 35));
        self::assertSame('high', RiskScale::fold('impact', 'critical', 3));
        self::assertSame('low', RiskScale::fold('impact', 'negligible', 53));
        self::assertSame('critical', RiskScale::fold('impact', 'critical', 4));
    }

    public function testCsvValuesFollowTheNumberOfLevelsOfEachAxis(): void
    {
        self::assertSame('probable', RiskRegisterCsv::mapValue('probability', 'certain', 4, [], 35));
        self::assertSame('probable', RiskRegisterCsv::mapValue('probability', '3', 3, [], 35));
        self::assertSame('critical', RiskRegisterCsv::mapValue('impact', '5', 5, [], 35));
        self::assertSame('high', RiskRegisterCsv::mapValue('impact', 'very_high', 4, [], 3));
    }

    public function testResidualScoreIsConvertedWithTheScale(): void
    {
        $row = [
            'probability' => 'certain', 'impact' => 'critical', 'risk_level' => 'critical', 'computed_score' => 16,
            'residual_probability' => 'rare', 'residual_impact' => 'low', 'residual_level' => 'low',
            'residual_score' => 1,
        ];
        $mapping = RiskScale::proposedMapping(4, 35);
        $scoring = new RiskScoringService(RiskMatrixDefaults::forSize(35), 35);
        $changes = RiskScaleMigration::convertRow($row, $mapping, $scoring, true);
        $expected = RiskScale::weight('probability', $mapping['probability']['rare'], 35)
            * RiskScale::weight('impact', $mapping['impact']['low'], 35);
        $converted = (float) ($changes['residual_score'] ?? $row['residual_score']);
        self::assertEqualsWithDelta((float) $expected, $converted, 0.001);

        // Risque résiduel non évalué : le score résiduel reste vide.
        $row = ['residual_probability' => '', 'residual_impact' => '', 'residual_level' => '', 'residual_score' => null]
            + $row;
        $changes = RiskScaleMigration::convertRow($row, $mapping, $scoring, true);
        self::assertArrayNotHasKey('residual_score', $changes);
    }
}

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
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScaleMigration;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #706 : taille de la matrice (4x4 par défaut, 5x5), calcul et correspondance.
 */
final class RiskScaleTest extends TestCase
{
    public function testDefaultSizeIsFourAndUnknownSizesFallBackToIt(): void
    {
        self::assertSame(4, RiskScale::DEFAULT_SIZE);
        self::assertSame(4, RiskScale::normalizeSize(3));
        self::assertSame(4, RiskScale::normalizeSize(0));
        self::assertSame(5, RiskScale::normalizeSize(5));
    }

    public function testFiveByFiveKeepsEveryFourByFourKey(): void
    {
        self::assertSame([], array_diff(RiskScale::probabilityKeys(4), RiskScale::probabilityKeys(5)));
        self::assertSame([], array_diff(RiskScale::impactKeys(4), RiskScale::impactKeys(5)));
        self::assertSame('very_rare', RiskScale::probabilityKeys(5)[0]);
        self::assertSame('negligible', RiskScale::impactKeys(5)[0]);
    }

    public function testWeightsAreTheRankOnTheAxis(): void
    {
        self::assertSame(1, RiskScale::weight('probability', 'rare', 4));
        self::assertSame(2, RiskScale::weight('probability', 'rare', 5));
        self::assertSame(5, RiskScale::weight('impact', 'critical', 5));
        self::assertSame(0, RiskScale::weight('impact', 'negligible', 4));
    }

    public function testFourByFourScoringIsUnchanged(): void
    {
        $service = new RiskScoringService(RiskMatrixDefaults::MATRIX, 4);

        self::assertSame(16.0, $service->score('certain', 'critical'));
        self::assertSame(6.0, $service->score('possible', 'high'));
        self::assertSame('medium', $service->level('possible', 'high'));
        self::assertSame(0.0, $service->score('very_rare', 'negligible'));
    }

    public function testFiveByFiveScoring(): void
    {
        $service = new RiskScoringService(RiskMatrixDefaults::MATRIX_5, 5);

        self::assertSame(1.0, $service->score('very_rare', 'negligible'));
        self::assertSame(25.0, $service->score('certain', 'critical'));
        self::assertSame(12.0, $service->score('possible', 'high'));
        self::assertSame('low', $service->level('very_rare', 'negligible'));
        self::assertSame('critical', $service->level('certain', 'critical'));
        self::assertSame('high', $service->level('possible', 'high'));
    }

    /**
     * La grille 5x5 par défaut suit les bandes de score 1-4, 5-9, 10-15, 16-25.
     */
    #[DataProvider('fiveByFiveProvider')]
    public function testDefaultFiveByFiveGridFollowsScoreBands(string $probability, string $impact, string $level): void
    {
        $service = new RiskScoringService(RiskMatrixDefaults::MATRIX_5, 5);

        self::assertSame($level, RiskScale::levelFromScore($service->score($probability, $impact), 5));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fiveByFiveProvider(): array
    {
        $cases = [];
        foreach (RiskMatrixDefaults::MATRIX_5 as $probability => $impacts) {
            foreach ($impacts as $impact => $level) {
                $cases["$probability x $impact"] = [$probability, $impact, $level];
            }
        }

        return $cases;
    }

    public function testDefaultGridsCoverEveryCombination(): void
    {
        foreach (RiskScale::SIZES as $size) {
            $matrix = RiskMatrixDefaults::forSize($size);
            self::assertSame(RiskScale::probabilityKeys($size), array_keys($matrix));
            foreach ($matrix as $impacts) {
                self::assertSame(RiskScale::impactKeys($size), array_keys($impacts));
                self::assertSame([], array_diff($impacts, RiskScale::LEVELS));
            }
        }
    }

    public function testFallbackThresholdsDependOnTheSize(): void
    {
        self::assertSame('medium', RiskScale::levelFromScore(4, 4));
        self::assertSame('low', RiskScale::levelFromScore(4, 5));
        self::assertSame('critical', RiskScale::levelFromScore(12, 4));
        self::assertSame('high', RiskScale::levelFromScore(12, 5));
        self::assertSame('critical', RiskScale::levelFromScore(16, 5));
    }

    public function testNormalizeMatrixKeepsValidCellsAndFillsTheRest(): void
    {
        $stored = RiskMatrixDefaults::MATRIX;
        $stored['rare']['low'] = 'critical';
        $stored['possible']['medium'] = 'unknown';

        $four = RiskScale::normalizeMatrix($stored, 4);
        self::assertSame('critical', $four['rare']['low']);
        self::assertSame(RiskMatrixDefaults::MATRIX['possible']['medium'], $four['possible']['medium']);

        // Grille 4x4 relue en 5x5 : les cases communes gardent leur valeur, les nouvelles prennent
        // celle de la grille 5x5 par défaut.
        $five = RiskScale::normalizeMatrix($stored, 5);
        self::assertSame('critical', $five['rare']['low']);
        self::assertSame(RiskMatrixDefaults::MATRIX_5['very_rare'], $five['very_rare']);
        self::assertCount(5, $five);
    }

    public function testSameSizeProposesIdentity(): void
    {
        $mapping = RiskScale::proposedMapping(4, 4);

        $probabilities = RiskScale::probabilityKeys(4);
        $impacts = RiskScale::impactKeys(4);
        self::assertSame(array_combine($probabilities, $probabilities), $mapping['probability']);
        self::assertSame(array_combine($impacts, $impacts), $mapping['impact']);
    }

    /**
     * La proposition de 4 vers 5 garde le niveau de 15 cases sur 16 avec les grilles par défaut, quand
     * garder les mêmes clés en changerait 9 (toutes vers le haut).
     */
    public function testProposedMappingFromFourToFiveKeepsTheMostLevels(): void
    {
        $mapping = RiskScale::proposedMapping(4, 5);

        self::assertSame(
            ['rare' => 'very_rare', 'possible' => 'rare', 'probable' => 'possible', 'certain' => 'certain'],
            $mapping['probability']
        );
        self::assertSame(
            ['low' => 'negligible', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
            $mapping['impact']
        );
        self::assertSame(1, self::changedCells(RiskMatrixDefaults::MATRIX, $mapping));
        self::assertSame(9, self::changedCells(RiskMatrixDefaults::MATRIX, self::sameKeys()));
    }

    /**
     * Correspondance de 4 vers 5 qui garde chaque clé telle quelle.
     *
     * @return array{probability: array<string, string>, impact: array<string, string>}
     */
    private static function sameKeys(): array
    {
        return RiskScale::sanitizeMapping([
            'probability' => array_combine(RiskScale::probabilityKeys(4), RiskScale::probabilityKeys(4)),
            'impact'      => array_combine(RiskScale::impactKeys(4), RiskScale::impactKeys(4)),
        ], 4, 5);
    }

    public function testProposedMappingFollowsACustomisedGrid(): void
    {
        // Grille 4x4 où tout vaut « low » : la grille 5x5 a le plus de « low » sur ses premières
        // lignes et colonnes, la proposition va donc vers le bas de l'échelle.
        $flat = array_fill_keys(RiskScale::probabilityKeys(4), array_fill_keys(RiskScale::impactKeys(4), 'low'));

        $mapping = RiskScale::proposedMapping(4, 5, $flat);

        self::assertSame('very_rare', $mapping['probability']['rare']);
        self::assertSame('negligible', $mapping['impact']['low']);
        self::assertNotSame('certain', $mapping['probability']['certain']);
    }

    public function testProposedMappingFromFiveToFourIsOrderedAndCoversTheScale(): void
    {
        $mapping = RiskScale::proposedMapping(5, 4);

        self::assertSame('rare', $mapping['probability']['very_rare']);
        self::assertSame('certain', $mapping['probability']['certain']);
        self::assertSame('low', $mapping['impact']['negligible']);
        self::assertSame('critical', $mapping['impact']['critical']);
        self::assertSame(RiskScale::probabilityKeys(4), array_values(array_unique($mapping['probability'])));
        self::assertSame(RiskScale::impactKeys(4), array_values(array_unique($mapping['impact'])));
    }

    /**
     * Nombre de cases de la grille 4x4 dont le niveau change avec la grille 5x5 par défaut.
     *
     * @param array<string, array<string, string>> $matrix
     * @param array{probability: array<string, string>, impact: array<string, string>} $mapping
     */
    private static function changedCells(array $matrix, array $mapping): int
    {
        $changed = 0;
        foreach ($matrix as $probability => $impacts) {
            foreach ($impacts as $impact => $level) {
                $row = RiskMatrixDefaults::MATRIX_5[$mapping['probability'][$probability]];
                $changed += (int) ($level !== $row[$mapping['impact'][$impact]]);
            }
        }

        return $changed;
    }

    public function testSanitizeMappingIsAClosedEnumeration(): void
    {
        $mapping = RiskScale::sanitizeMapping([
            'probability' => ['rare' => 'very_rare', 'possible' => 'drop table', 'other' => 'certain'],
            'impact'      => ['low' => 'negligible', 'medium' => ['x']],
        ], 4, 5);

        self::assertSame('very_rare', $mapping['probability']['rare']);
        $proposed = RiskScale::proposedMapping(4, 5);
        self::assertSame($proposed['probability']['possible'], $mapping['probability']['possible']);
        self::assertArrayNotHasKey('other', $mapping['probability']);
        self::assertSame('negligible', $mapping['impact']['low']);
        self::assertSame('medium', $mapping['impact']['medium']);
    }

    public function testConvertRowFromFourToFiveKeepsValuesAndRecomputesLevels(): void
    {
        $row = [
            'probability' => 'rare', 'impact' => 'high', 'risk_level' => 'low', 'computed_score' => '3.00',
            'residual_probability' => '', 'residual_impact' => '', 'residual_level' => '',
        ];
        $scoring = new RiskScoringService(RiskMatrixDefaults::MATRIX_5, 5);

        $changes = RiskScaleMigration::convertRow($row, self::sameKeys(), $scoring, true);

        // rare (2) x high (4) = 8 en 5x5 : medium. Valeurs gardées, résiduel non évalué inchangé.
        self::assertSame(['risk_level' => 'medium', 'computed_score' => 8.0], $changes);
    }

    public function testConvertRowAppliesTheValidatedMapping(): void
    {
        $row = [
            'probability' => 'rare', 'impact' => 'low', 'risk_level' => 'low', 'computed_score' => '1.00',
            'residual_probability' => 'rare', 'residual_impact' => 'medium', 'residual_level' => 'low',
        ];
        $mapping = RiskScale::sanitizeMapping(
            ['probability' => ['rare' => 'very_rare'], 'impact' => ['low' => 'negligible']],
            4,
            5
        );
        $scoring = new RiskScoringService(RiskMatrixDefaults::MATRIX_5, 5);

        $changes = RiskScaleMigration::convertRow($row, $mapping, $scoring, true);

        self::assertSame('very_rare', $changes['probability']);
        self::assertSame('negligible', $changes['impact']);
        // very_rare x negligible vaut 1 en 5x5, comme rare x low en 4x4 : ni score ni niveau ne bougent.
        self::assertArrayNotHasKey('computed_score', $changes);
        self::assertArrayNotHasKey('risk_level', $changes);
        self::assertSame('very_rare', $changes['residual_probability']);
        self::assertArrayNotHasKey('residual_impact', $changes);
        self::assertArrayNotHasKey('residual_level', $changes);
    }

    public function testConvertRowFromFiveToFour(): void
    {
        $row = [
            'probability' => 'very_rare', 'impact' => 'negligible', 'risk_level' => 'low', 'computed_score' => '1.00',
        ];
        $scoring = new RiskScoringService(RiskMatrixDefaults::MATRIX, 4);

        $changes = RiskScaleMigration::convertRow($row, RiskScale::proposedMapping(5, 4), $scoring, false);

        self::assertSame(['probability' => 'rare', 'impact' => 'low'], $changes);
    }

    public function testRowWithNothingToChangeGivesNoChange(): void
    {
        $row = [
            'probability' => 'possible', 'impact' => 'medium', 'risk_level' => 'medium', 'computed_score' => '4.00',
        ];
        $scoring = new RiskScoringService(RiskMatrixDefaults::MATRIX, 4);

        self::assertSame([], RiskScaleMigration::convertRow($row, RiskScale::proposedMapping(4, 4), $scoring, false));
    }

    public function testSummaryCountsLevelMoves(): void
    {
        $rows = [
            // rare x high : low en 4x4, medium en 5x5 (monte).
            ['probability' => 'rare', 'impact' => 'high', 'risk_level' => 'low', 'computed_score' => '3.00'],
            // certain x high : critical des deux côtés, seul le score change.
            ['probability' => 'certain', 'impact' => 'high', 'risk_level' => 'critical', 'computed_score' => '12.00'],
            // certain x low : medium en 4x4, high en 5x5 (monte).
            ['probability' => 'certain', 'impact' => 'low', 'risk_level' => 'medium', 'computed_score' => '4.00'],
            // probable x critical : critical des deux côtés, seul le score change.
            [
                'probability' => 'probable', 'impact' => 'critical', 'risk_level' => 'critical',
                'computed_score' => '12.00',
            ],
        ];
        $scoring = new RiskScoringService(RiskMatrixDefaults::MATRIX_5, 5);

        $summary = RiskScaleMigration::summarize($rows, self::sameKeys(), $scoring, false);

        self::assertSame(4, $summary['total']);
        self::assertSame(4, $summary['changed']);
        self::assertSame(2, $summary['raised']);
        self::assertSame(0, $summary['lowered']);
        self::assertSame(['low' => ['medium' => 1], 'medium' => ['high' => 1]], $summary['level_moves']);

        $merged = RiskScaleMigration::merge($summary, $summary);
        self::assertSame(8, $merged['total']);
        self::assertSame(['medium' => 2], $merged['level_moves']['low']);
    }
}

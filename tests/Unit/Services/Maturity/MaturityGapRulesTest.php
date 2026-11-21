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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Maturity;

use GlpiPlugin\Grcmanager\Services\Maturity\MaturityGapRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #603 : règles de la maturité par mesure (niveaux, écarts, tri, synthèse, comparaison).
 */
final class MaturityGapRulesTest extends TestCase
{
    public function testLevelsAreZeroToFiveOrNull(): void
    {
        $this->assertSame([0, 1, 2, 3, 4, 5], Rules::levels());
        $this->assertSame(0, Rules::normalizeLevel('0'));
        $this->assertSame(5, Rules::normalizeLevel(5));
        $this->assertSame(3, Rules::normalizeLevel(' 3 '));
        foreach (['', null, '6', '-1', 'abc', '2.5', true, []] as $invalid) {
            $this->assertNull(Rules::normalizeLevel($invalid), var_export($invalid, true));
        }
    }

    public function testGapIsTargetMinusCurrentNeverNegative(): void
    {
        $this->assertSame(2, Rules::gap(1, 3));
        $this->assertSame(0, Rules::gap(4, 3), 'Cible dépassée : aucun écart.');
        $this->assertSame(0, Rules::gap(3, 3));
        $this->assertNull(Rules::gap(null, 3));
        $this->assertNull(Rules::gap(2, null));
    }

    public function testDatesAndTexts(): void
    {
        $this->assertSame('2027-06-30', Rules::normalizeDate('2027-06-30'));
        $this->assertNull(Rules::normalizeDate('2027-02-30'));
        $this->assertNull(Rules::normalizeDate('30/06/2027'));
        $this->assertNull(Rules::normalizeDate(null));
        $this->assertSame('abc', Rules::cleanText("  a\0bc  "));
        $this->assertSame('ab', Rules::cleanText('abcdef', 2));
        $this->assertSame('', Rules::cleanText(['x']));
    }

    public function testLabelsKeepKnownLevelsAndFallBackToDefaults(): void
    {
        $clean = Rules::sanitizeLabels([
            '0' => ' Absent ', 3 => '', 9 => 'Unknown', 'x' => 'y', 5 => str_repeat('a', 80),
        ]);
        $this->assertSame([0 => 'Absent', 5 => str_repeat('a', Rules::LABEL_MAX_LENGTH)], $clean);

        $defaults = [0 => 'D0', 1 => 'D1', 2 => 'D2', 3 => 'D3', 4 => 'D4', 5 => 'D5'];
        $merged = Rules::mergeLabels($defaults, [1 => 'Custom']);
        $this->assertSame('Custom', $merged[1]);
        $this->assertSame('D4', $merged[4]);
        $this->assertCount(6, $merged);
    }

    public function testBadgeClassesFollowTheLevel(): void
    {
        $this->assertStringContainsString('red', Rules::levelBadgeClass(0));
        $this->assertStringContainsString('green', Rules::levelBadgeClass(5));
        $this->assertStringContainsString('secondary', Rules::levelBadgeClass(null));
        $this->assertStringContainsString('green', Rules::gapBadgeClass(0));
        $this->assertStringContainsString('yellow', Rules::gapBadgeClass(1));
        $this->assertStringContainsString('orange', Rules::gapBadgeClass(2));
        $this->assertStringContainsString('red', Rules::gapBadgeClass(4));
    }

    public function testGapsAreSortedBySizeThenDateThenCode(): void
    {
        $rows = [
            ['code' => 'A.5.10', 'gap' => 2, 'target_date' => null],
            ['code' => 'A.5.2', 'gap' => 2, 'target_date' => '2027-01-31'],
            ['code' => 'A.8.1', 'gap' => 0, 'target_date' => '2026-01-01'],
            ['code' => 'A.5.9', 'gap' => 2, 'target_date' => null],
            ['code' => 'A.6.1', 'gap' => 3, 'target_date' => '2028-01-01'],
            ['code' => 'A.7.1', 'gap' => null, 'target_date' => null],
        ];
        $this->assertSame(
            ['A.6.1', 'A.5.2', 'A.5.9', 'A.5.10'],
            array_column(Rules::sortGaps($rows), 'code'),
            'Sans écart ni niveau : écartées ; puis échéance, puis code naturel.'
        );
    }

    public function testSummaryAndThemeAverages(): void
    {
        $rows = [
            ['theme' => 'organizational', 'current' => 1, 'target' => 3],
            ['theme' => 'organizational', 'current' => 2, 'target' => 2],
            ['theme' => 'technological', 'current' => 4, 'target' => 5],
            ['theme' => 'technological', 'current' => 3, 'target' => null],
            ['theme' => 'people', 'current' => null, 'target' => 4],
        ];
        $summary = Rules::summary($rows);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(4, $summary['assessed']);
        $this->assertSame(4, $summary['targeted']);
        $this->assertSame(2, $summary['gaps']);
        $this->assertSame(3, $summary['gap_points']);
        $this->assertSame(2.3, $summary['average_current']);
        $this->assertSame(3.3, $summary['average_target']);

        $themes = Rules::themeAverages($rows, ['organizational', 'people', 'physical', 'technological']);
        $this->assertSame(['organizational', 'technological'], array_keys($themes));
        $this->assertSame(['current' => 1.5, 'target' => 2.5, 'count' => 2], $themes['organizational']);
        $this->assertSame(['current' => 4.0, 'target' => 5.0, 'count' => 1], $themes['technological']);

        $empty = Rules::summary([]);
        $this->assertNull($empty['average_current']);
        $this->assertSame(0, $empty['gaps']);
    }

    public function testComparisonOfTwoCampaigns(): void
    {
        $result = Rules::compare(
            ['A.5.1' => 1, 'A.5.2' => 3, 'A.5.3' => 2, 'A.5.10' => null],
            ['A.5.1' => 2, 'A.5.2' => 2, 'A.5.3' => 2, 'A.5.10' => 4, 'A.8.1' => 1]
        );
        $this->assertSame(['A.5.1', 'A.5.2', 'A.5.3', 'A.5.10', 'A.8.1'], array_keys($result['lines']));
        $this->assertSame(1, $result['lines']['A.5.1']['delta']);
        $this->assertSame(-1, $result['lines']['A.5.2']['delta']);
        $this->assertNull($result['lines']['A.5.10']['delta'], 'Évaluée dans une seule campagne.');
        $this->assertNull($result['lines']['A.8.1']['before']);
        $this->assertSame(1, $result['improved']);
        $this->assertSame(1, $result['declined']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(3, $result['compared']);
    }

    public function testFindingPresetIsAnImprovementOnlyWithAGap(): void
    {
        $this->assertNull(Rules::findingPreset(null));
        $this->assertNull(Rules::findingPreset(0));
        $this->assertSame(['finding_type' => 'improvement', 'severity' => 'minor'], Rules::findingPreset(2));
        $this->assertSame(['finding_type' => 'improvement', 'severity' => 'major'], Rules::findingPreset(3));
    }
}

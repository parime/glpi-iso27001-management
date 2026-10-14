<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class AnssiHygieneCatalogDefaultsTest extends TestCase
{
    public function testExactly42MeasuresNumberedOneToFortyTwo(): void
    {
        self::assertCount(42, AnssiHygieneCatalogDefaults::MEASURES);
        self::assertSame(range(1, 42), array_keys(AnssiHygieneCatalogDefaults::MEASURES));
    }

    public function testTenThemesWithTheGuideDistribution(): void
    {
        self::assertCount(10, AnssiHygieneCatalogDefaults::THEMES);

        $perTheme = array_count_values(array_column(AnssiHygieneCatalogDefaults::MEASURES, 'theme'));
        // Répartition de l'« Outil de suivi » du guide (version 2.0, septembre 2017).
        self::assertSame(
            ['I' => 3, 'II' => 4, 'III' => 6, 'IV' => 5, 'V' => 8, 'VI' => 3, 'VII' => 4, 'VIII' => 2,
                'IX' => 5, 'X' => 2],
            $perTheme
        );
    }

    public function testEveryMeasureIsBilingual(): void
    {
        foreach (AnssiHygieneCatalogDefaults::MEASURES as $number => $measure) {
            self::assertArrayHasKey($measure['theme'], AnssiHygieneCatalogDefaults::THEMES, "mesure $number");
            self::assertNotSame('', trim($measure['fr']), "mesure $number fr");
            self::assertNotSame('', trim($measure['en']), "mesure $number en");
        }
    }

    public function testEveryAnnexAReferenceExistsAndOnlyRiskAnalysisIsUnmapped(): void
    {
        foreach (AnssiHygieneCatalogDefaults::MEASURES as $number => $measure) {
            if ($number === AnssiHygieneCatalogDefaults::RISK_ANALYSIS_MEASURE) {
                self::assertSame([], $measure['annex_a']);
                continue;
            }
            self::assertNotEmpty($measure['annex_a'], "mesure $number");
            foreach ($measure['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$number -> $annexACode");
            }
        }
    }

    public function testReverseLookup(): void
    {
        self::assertSame([13], AnssiHygieneCatalogDefaults::measuresForControl('A.8.5'));
        self::assertSame([37], AnssiHygieneCatalogDefaults::measuresForControl('A.8.13'));
        self::assertSame([], AnssiHygieneCatalogDefaults::measuresForControl('A.7.12'));
    }
}

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso42001CatalogDefaults;
use PHPUnit\Framework\TestCase;

final class Iso42001CatalogDefaultsTest extends TestCase
{
    public function testAnnexAGroupsA2ToA10AreAllPresentAndBilingual(): void
    {
        $expected = array_map(static fn (int $n): string => 'A.' . $n, range(2, 10));
        self::assertSame($expected, array_keys(Iso42001CatalogDefaults::OBJECTIVES));
        foreach (Iso42001CatalogDefaults::OBJECTIVES as $code => $objective) {
            self::assertArrayHasKey($objective['theme'], Iso42001CatalogDefaults::THEMES, $code);
            self::assertNotSame('', trim($objective['fr']), "$code fr");
            self::assertNotSame('', trim($objective['en']), "$code en");
        }
    }

    public function testEveryThemeHasAtLeastOneGroup(): void
    {
        $themes = array_column(Iso42001CatalogDefaults::OBJECTIVES, 'theme');
        foreach (array_keys(Iso42001CatalogDefaults::THEMES) as $theme) {
            self::assertContains($theme, $themes, "Thème $theme vide");
        }
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (Iso42001CatalogDefaults::OBJECTIVES as $code => $objective) {
            self::assertNotEmpty($objective['annex_a'], $code);
            foreach ($objective['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($objective['modules'] as $module) {
                self::assertContains($module, Iso42001CatalogDefaults::MODULES, "$code -> $module");
            }
        }
    }

    public function testReverseMapping(): void
    {
        self::assertContains('A.10', Iso42001CatalogDefaults::objectivesForControl('A.5.19'));
        self::assertContains('A.6', Iso42001CatalogDefaults::objectivesForControl('A.8.25'));
        self::assertSame([], Iso42001CatalogDefaults::objectivesForControl('A.7.12'));
    }
}

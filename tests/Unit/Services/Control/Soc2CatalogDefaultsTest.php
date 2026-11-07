<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Soc2CatalogDefaults;
use PHPUnit\Framework\TestCase;

final class Soc2CatalogDefaultsTest extends TestCase
{
    public function testCommonCriteriaCc1ToCc9ArePresentAndBilingual(): void
    {
        $common = array_keys(array_filter(
            Soc2CatalogDefaults::CRITERIA,
            static fn (array $criterion): bool => $criterion['category'] === 'common'
        ));
        self::assertSame(array_map(static fn (int $n): string => 'CC' . $n, range(1, 9)), $common);
        foreach (Soc2CatalogDefaults::CRITERIA as $code => $criterion) {
            self::assertArrayHasKey($criterion['category'], Soc2CatalogDefaults::CATEGORIES, $code);
            self::assertNotSame('', trim($criterion['fr']), "$code fr");
            self::assertNotSame('', trim($criterion['en']), "$code en");
        }
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (Soc2CatalogDefaults::CRITERIA as $code => $criterion) {
            self::assertNotEmpty($criterion['annex_a'], $code);
            foreach ($criterion['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($criterion['modules'] as $module) {
                self::assertContains($module, Soc2CatalogDefaults::MODULES, "$code -> $module");
            }
        }
    }

    public function testReverseMapping(): void
    {
        self::assertSame(['CC9', 'A1'], Soc2CatalogDefaults::criteriaForControl('A.5.30'));
        self::assertSame(['CC8'], Soc2CatalogDefaults::criteriaForControl('A.8.32'));
        self::assertSame([], Soc2CatalogDefaults::criteriaForControl('A.7.12'));
    }
}

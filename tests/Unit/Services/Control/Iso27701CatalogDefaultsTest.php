<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27701CatalogDefaults;
use PHPUnit\Framework\TestCase;

final class Iso27701CatalogDefaultsTest extends TestCase
{
    public function testEveryObjectiveIsBilingualAndBelongsToItsTable(): void
    {
        foreach (Iso27701CatalogDefaults::OBJECTIVES as $code => $objective) {
            self::assertArrayHasKey($objective['table'], Iso27701CatalogDefaults::TABLES, $code);
            self::assertTrue(
                $code === $objective['table'] || str_starts_with($code, $objective['table'] . '.'),
                $code
            );
            self::assertNotSame('', trim($objective['fr']), "$code fr");
            self::assertNotSame('', trim($objective['en']), "$code en");
        }
    }

    public function testEveryTableHasAtLeastOneObjective(): void
    {
        $tables = array_column(Iso27701CatalogDefaults::OBJECTIVES, 'table');
        foreach (array_keys(Iso27701CatalogDefaults::TABLES) as $table) {
            self::assertContains($table, $tables, "Table $table sans objectif");
        }
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (Iso27701CatalogDefaults::OBJECTIVES as $code => $objective) {
            self::assertNotEmpty($objective['annex_a'], $code);
            foreach ($objective['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($objective['modules'] as $module) {
                self::assertContains($module, Iso27701CatalogDefaults::MODULES, "$code -> $module");
            }
        }
    }

    public function testReverseMapping(): void
    {
        self::assertContains('A.1.2', Iso27701CatalogDefaults::objectivesForControl('A.5.34'));
        self::assertContains('A.3', Iso27701CatalogDefaults::objectivesForControl('A.5.24'));
        self::assertSame([], Iso27701CatalogDefaults::objectivesForControl('A.7.12'));
    }
}

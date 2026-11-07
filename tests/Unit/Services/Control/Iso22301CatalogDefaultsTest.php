<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso22301CatalogDefaults;
use PHPUnit\Framework\TestCase;

final class Iso22301CatalogDefaultsTest extends TestCase
{
    public function testEveryRequirementIsBilingualAndBelongsToItsClause(): void
    {
        foreach (Iso22301CatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertArrayHasKey((int) $requirement['clause'], Iso22301CatalogDefaults::CLAUSES, $code);
            self::assertStringStartsWith($requirement['clause'] . '.', $code);
            self::assertNotSame('', trim($requirement['fr']), "$code fr");
            self::assertNotSame('', trim($requirement['en']), "$code en");
        }
    }

    public function testEveryClauseHasAtLeastOneRequirement(): void
    {
        $clauses = array_column(Iso22301CatalogDefaults::REQUIREMENTS, 'clause');
        foreach (array_keys(Iso22301CatalogDefaults::CLAUSES) as $clause) {
            self::assertContains((string) $clause, $clauses, "Article $clause sans exigence");
        }
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (Iso22301CatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertNotEmpty($requirement['annex_a'], $code);
            foreach ($requirement['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($requirement['modules'] as $module) {
                self::assertContains($module, Iso22301CatalogDefaults::MODULES, "$code -> $module");
            }
        }
    }

    public function testReverseMapping(): void
    {
        self::assertContains('8.2', Iso22301CatalogDefaults::requirementsForControl('A.5.30'));
        self::assertContains('8.5', Iso22301CatalogDefaults::requirementsForControl('A.5.30'));
        self::assertSame([], Iso22301CatalogDefaults::requirementsForControl('A.7.12'));
    }
}

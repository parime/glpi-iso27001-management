<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\PciDssCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class PciDssCatalogDefaultsTest extends TestCase
{
    public function testTheTwelvePrincipalRequirementsArePresentAndBilingual(): void
    {
        self::assertSame(
            array_map('strval', range(1, 12)),
            array_map('strval', array_keys(PciDssCatalogDefaults::REQUIREMENTS))
        );
        foreach (PciDssCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertArrayHasKey($requirement['goal'], PciDssCatalogDefaults::GOALS, (string) $code);
            self::assertNotSame('', trim($requirement['fr']), "$code fr");
            self::assertNotSame('', trim($requirement['en']), "$code en");
        }
    }

    public function testEveryGoalHasAtLeastOneRequirement(): void
    {
        $goals = array_column(PciDssCatalogDefaults::REQUIREMENTS, 'goal');
        foreach (array_keys(PciDssCatalogDefaults::GOALS) as $goal) {
            self::assertContains($goal, $goals, "Objectif $goal vide");
        }
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (PciDssCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            self::assertNotEmpty($requirement['annex_a'], (string) $code);
            foreach ($requirement['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($requirement['modules'] as $module) {
                self::assertContains($module, PciDssCatalogDefaults::MODULES, "$code -> $module");
            }
        }
    }

    public function testReverseMapping(): void
    {
        self::assertSame(['6', '11'], PciDssCatalogDefaults::requirementsForControl('A.8.8'));
        self::assertSame(['1'], PciDssCatalogDefaults::requirementsForControl('A.8.20'));
        self::assertSame([], PciDssCatalogDefaults::requirementsForControl('A.5.30'));
    }
}

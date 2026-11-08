<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\HdsCatalogDefaults;
use PHPUnit\Framework\TestCase;

final class HdsCatalogDefaultsTest extends TestCase
{
    public function testTheThirtyOneRequirementsOfV2AreAllThere(): void
    {
        self::assertSame(range(1, 31), array_map('intval', array_keys(HdsCatalogDefaults::REQUIREMENTS)));
    }

    public function testChaptersFollowTheFramework(): void
    {
        foreach (HdsCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            $code = (int) $code;
            $expected = match (true) {
                $code <= 16 => 'smsi',
                $code <= 27 => 'contract',
                default     => 'sovereignty',
            };
            self::assertSame($expected, $requirement['chapter'], HdsCatalogDefaults::label($code));
            foreach (['fr', 'en'] as $lang) {
                self::assertNotSame('', trim($requirement[$lang]), HdsCatalogDefaults::label($code) . " $lang");
            }
        }
        self::assertStringContainsString('23-1', HdsCatalogDefaults::REQUIREMENTS[23]['fr']);
    }

    public function testMappingsPointToKnownControlsAndModules(): void
    {
        foreach (HdsCatalogDefaults::REQUIREMENTS as $code => $requirement) {
            $label = HdsCatalogDefaults::label($code);
            self::assertNotEmpty($requirement['annex_a'], $label);
            foreach ($requirement['annex_a'] as $annexA) {
                self::assertArrayHasKey($annexA, ControlCatalogDefaults::CONTROLS, "$label -> $annexA");
            }
            foreach ($requirement['modules'] as $module) {
                self::assertContains($module, HdsCatalogDefaults::MODULES, "$label -> $module");
            }
        }
    }

    public function testRequirementsForControlUsesTheOfficialNumbering(): void
    {
        self::assertSame('EXI 05', HdsCatalogDefaults::label(5));
        self::assertSame(['EXI 10'], HdsCatalogDefaults::requirementsForControl('A.6.3'));
        self::assertSame([], HdsCatalogDefaults::requirementsForControl('A.7.12'));
    }
}

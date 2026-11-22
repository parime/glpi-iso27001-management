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

namespace GlpiPlugin\Grcmanager\Tests\Config;

use GlpiPlugin\Grcmanager\Config\Block\FrameworksCustomBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\FrameworkFamilies;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkFamilyRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1053 : référentiels rangés par famille, déclarées par fichier dans src/Config/Block.
 */
final class FrameworkFamiliesTest extends TestCase
{
    public function testSixFamiliesInTheFrameworksTabInOrder(): void
    {
        $ids = array_map(static fn (string $class): string => $class::domId(), FrameworkFamilies::classes());

        $this->assertSame([
            'grc-frameworks-iso', 'grc-frameworks-europe', 'grc-frameworks-national',
            'grc-frameworks-sector', 'grc-frameworks-catalog', 'grc-frameworks-custom',
        ], $ids);
        $keys = array_map(static fn (string $block): string => $block::key(), ConfigTabs::blocksOf('frameworks'));
        $this->assertSame('Frameworks', $keys[0]);
        $this->assertSame(['Recyf', 'Acn'], array_slice($keys, -2));
    }

    public function testEveryBuiltInFrameworkIsDeclaredInAFamily(): void
    {
        $declared = [];
        foreach (FrameworkFamilies::classes() as $class) {
            if ($class !== FrameworksCustomBlock::class) {
                $declared = array_merge($declared, FrameworkFamilyRules::keys($class::members()));
            }
        }
        foreach (array_keys(FrameworkSelection::FRAMEWORKS) as $key) {
            $this->assertContains($key, $declared, "référentiel $key sans famille");
        }
        foreach ($declared as $key) {
            $this->assertArrayHasKey($key, FrameworkSelection::FRAMEWORKS, $key);
        }
    }

    public function testNationalImplementationsBelongToNis2(): void
    {
        $this->assertSame([
            FrameworkSelection::RECYF  => FrameworkSelection::NIS2,
            FrameworkSelection::ACN_IT => FrameworkSelection::NIS2,
        ], FrameworkFamilies::parents());
    }
}

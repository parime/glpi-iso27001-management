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

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\FrameworkFamilyRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1053 : familles et regroupements de référentiels (logique pure).
 */
final class FrameworkFamilyRulesTest extends TestCase
{
    private const EUROPE = [
        ['group' => 'nis2', 'parent' => 'nis2', 'children' => ['recyf', 'acn_it']],
        'dora',
    ];

    private const NATIONAL = [
        ['group' => 'anssi', 'children' => ['anssi', 'mss', 'recyf']],
    ];

    public function testKeysAndParents(): void
    {
        $this->assertSame(['nis2', 'recyf', 'acn_it', 'dora'], FrameworkFamilyRules::keys(self::EUROPE));
        $this->assertSame(['recyf' => 'nis2', 'acn_it' => 'nis2'], FrameworkFamilyRules::parents(self::EUROPE));
        $this->assertSame([], FrameworkFamilyRules::parents(self::NATIONAL));
    }

    public function testCountShowsOnlyEffectivelyActiveFrameworks(): void
    {
        $keys = FrameworkFamilyRules::keys(self::EUROPE);

        $this->assertSame(
            ['active' => 2, 'total' => 4],
            FrameworkFamilyRules::count($keys, ['nis2', 'acn_it', 'anssi'])
        );
        $this->assertSame(['active' => 0, 'total' => 4], FrameworkFamilyRules::count($keys, []));
        // ReCyF compté dans les deux familles où il figure.
        $this->assertSame(
            ['active' => 2, 'total' => 3],
            FrameworkFamilyRules::count(FrameworkFamilyRules::keys(self::NATIONAL), ['recyf', 'mss'])
        );
    }

    public function testArrangePlacesImplementationsUnderTheirParentAndKeepsOrphans(): void
    {
        $families = [
            ['key' => 'europe', 'label' => 'EU', 'icon' => 'ti-a', 'keys' => FrameworkFamilyRules::keys(self::EUROPE),
                'parents' => FrameworkFamilyRules::parents(self::EUROPE)],
            ['key' => 'iso', 'label' => 'ISO', 'icon' => 'ti-b', 'keys' => ['iso27001'], 'parents' => []],
            ['key' => 'custom', 'label' => 'Custom', 'icon' => 'ti-c', 'keys' => [], 'parents' => []],
        ];
        $assessments = [
            'NIS2 Italia' => ['key' => 'acn_it'],
            'ISO 27001'   => ['key' => 'iso27001'],
            'NIS2'        => ['key' => 'nis2'],
            'Imported'    => ['key' => 'custom_4'],
        ];

        $arranged = FrameworkFamilyRules::arrange($families, $assessments);

        $this->assertSame(['europe', 'iso', 'custom'], array_column($arranged, 'key'));
        $this->assertSame(['NIS2', 'NIS2 Italia'], array_column($arranged[0]['rows'], 'label'));
        $this->assertSame([false, true], array_column($arranged[0]['rows'], 'nested'));
        $this->assertSame(['Imported'], array_column($arranged[2]['rows'], 'label'));
        $this->assertSame([], FrameworkFamilyRules::arrange($families, []));
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use PHPUnit\Framework\TestCase;

final class FrameworkSelectionTest extends TestCase
{
    public function testMissingOrUnreadableSettingKeepsEverythingEnabled(): void
    {
        foreach ([null, '', 'pas du json', '"iso27001"', '{"a": 1}'] as $stored) {
            $this->assertSame([], FrameworkSelection::decode($stored));
            $this->assertSame(
                array_keys(FrameworkSelection::FRAMEWORKS),
                FrameworkSelection::enabled(FrameworkSelection::decode($stored))
            );
        }
    }

    public function testEncodeStoresTheUntickedFrameworks(): void
    {
        $stored = FrameworkSelection::encode([FrameworkSelection::NIS2, FrameworkSelection::ANSSI]);

        $this->assertSame(
            [FrameworkSelection::ISO27001, FrameworkSelection::NIST_CSF, FrameworkSelection::CIS_CONTROLS,
                FrameworkSelection::ISO27090, FrameworkSelection::DORA,
                FrameworkSelection::ISO27701, FrameworkSelection::ISO22301,
                FrameworkSelection::ISO42001, FrameworkSelection::PCI_DSS,
                FrameworkSelection::SOC2],
            FrameworkSelection::decode($stored)
        );
        $this->assertSame(
            [FrameworkSelection::NIS2, FrameworkSelection::ANSSI],
            FrameworkSelection::enabled(FrameworkSelection::decode($stored))
        );
    }

    public function testUnknownValuesAreIgnored(): void
    {
        // Formulaire forgé : valeur inconnue, tableau imbriqué, doublon.
        $stored = FrameworkSelection::encode(['nis2', 'nis2', '<script>', ['iso27001'], 42]);
        $this->assertSame(['nis2'], FrameworkSelection::enabled(FrameworkSelection::decode($stored)));

        $this->assertSame(['anssi'], FrameworkSelection::decode('["anssi", "inconnu", 3, null]'));
    }

    public function testAllTickedAndNoneTicked(): void
    {
        $this->assertSame('[]', FrameworkSelection::encode(array_keys(FrameworkSelection::FRAMEWORKS)));
        $this->assertSame([], FrameworkSelection::enabled(FrameworkSelection::decode(FrameworkSelection::encode([]))));
    }
}

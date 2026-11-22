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

namespace GlpiPlugin\Grcmanager\Tests\Install;

use GlpiPlugin\Grcmanager\Install\FrameworkFamiliesTables;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1053 : reprise du réglage des référentiels, sans perte d'activation.
 */
final class FrameworkFamiliesTablesTest extends TestCase
{
    private const KNOWN = ['iso27001', 'nis2', 'anssi', 'acn_it'];

    public function testAnUpdateWithNis2KeepsTheFrenchImplementation(): void
    {
        $this->assertSame([FrameworkSelection::RECYF], FrameworkFamiliesTables::toEnable([], self::KNOWN, true, false));
        $this->assertSame([], FrameworkFamiliesTables::toEnable(['nis2'], ['iso27001', 'nis2'], true, false));
    }

    public function testExistingReCyfDataKeepsTheFrenchImplementation(): void
    {
        $this->assertSame([FrameworkSelection::RECYF], FrameworkFamiliesTables::toEnable([], [], false, true));
    }

    public function testItalyEnabledAloneKeepsNis2(): void
    {
        $this->assertSame(
            [FrameworkSelection::NIS2],
            FrameworkFamiliesTables::toEnable(['nis2'], self::KNOWN, true, false)
        );
        $this->assertSame(
            [FrameworkSelection::RECYF],
            FrameworkFamiliesTables::toEnable(['acn_it'], self::KNOWN, true, false),
            'Italie désactivée : rien à cocher pour elle'
        );
    }

    public function testANewInstallationHasNoImplementation(): void
    {
        $this->assertSame([], FrameworkFamiliesTables::toEnable([], [], false, false));
    }
}

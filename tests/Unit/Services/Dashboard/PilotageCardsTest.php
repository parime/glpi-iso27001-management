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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use PHPUnit\Framework\TestCase;

final class PilotageCardsTest extends TestCase
{
    public function testFrameworkRatesIgnoreNonApplicableAndUnmappedControls(): void
    {
        // Une mesure liée à NIS2 et une qui ne l'est pas, trouvées dans le catalogue.
        $nis2 = null;
        $other = null;
        foreach (['A.5.1', 'A.5.2', 'A.5.7', 'A.5.24', 'A.7.4', 'A.7.13', 'A.8.7'] as $code) {
            if (Nis2CatalogDefaults::requirementsForControl($code) !== []) {
                $nis2 ??= $code;
            } else {
                $other ??= $code;
            }
        }
        $this->assertNotNull($nis2);
        $this->assertNotNull($other);

        $rates = PilotageCards::frameworkRates([
            $nis2    => ['applicability' => 'yes', 'implementation_status' => 'implemented'],
            $other   => ['applicability' => 'yes', 'implementation_status' => 'not_started'],
            'A.8.99' => ['applicability' => 'no', 'implementation_status' => 'not_started'],
        ]);
        $this->assertSame(50.0, $rates['ISO 27001'], 'Les mesures non applicables ne comptent pas.');
        $this->assertSame(100.0, $rates['NIS2'], 'Seules les mesures liées à NIS2 comptent.');
        $this->assertSame([], array_diff(['ISO 27001', 'NIS2', 'ANSSI'], array_keys($rates)));
    }

    public function testFrameworkRatesWithoutControls(): void
    {
        $this->assertSame(['ISO 27001' => null, 'NIS2' => null, 'ANSSI' => null], PilotageCards::frameworkRates([]));
    }
}

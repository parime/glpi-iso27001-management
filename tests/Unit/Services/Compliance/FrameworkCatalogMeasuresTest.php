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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkCatalogMeasures;
use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use PHPUnit\Framework\TestCase;

/**
 * Issue #789 : mesures propres de chaque référentiel intégré, lues dans son catalogue.
 */
final class FrameworkCatalogMeasuresTest extends TestCase
{
    public function testEveryMappedBuiltinFrameworkHasItsOwnMeasures(): void
    {
        foreach (PilotageCards::frameworkFilters() as $label => [$key]) {
            if ($key === FrameworkSelection::ISO27001) {
                $this->assertSame([], FrameworkCatalogMeasures::measures($key));
                continue;
            }
            $measures = FrameworkCatalogMeasures::measures($key, 'en');
            $this->assertNotSame([], $measures, $label);
            foreach ($measures as $measure) {
                $this->assertNotSame('', $measure['code'], $label);
                $this->assertNotSame('', $measure['title'], $label . ' ' . $measure['code']);
                foreach ($measure['annex_a'] as $code) {
                    $this->assertMatchesRegularExpression('/^A\.\d+\.\d+$/', $code, $label);
                }
            }
        }
    }

    public function testCatalogSizesAndReferences(): void
    {
        $nis2 = FrameworkCatalogMeasures::measures(FrameworkSelection::NIS2, 'fr');
        $this->assertCount(count(Nis2CatalogDefaults::REQUIREMENTS), $nis2);
        $this->assertSame(Nis2CatalogDefaults::reference('20.1'), $nis2[0]['code']);
        $this->assertSame(Nis2CatalogDefaults::REQUIREMENTS['20.1']['annex_a'], $nis2[0]['annex_a']);

        $this->assertCount(
            count(AnssiHygieneCatalogDefaults::MEASURES),
            FrameworkCatalogMeasures::measures(FrameworkSelection::ANSSI)
        );
        $cis = FrameworkCatalogMeasures::measures(FrameworkSelection::CIS_CONTROLS);
        $this->assertCount(count(CisControlsCatalogDefaults::CONTROLS), $cis);
        $this->assertSame('CIS 1', $cis[0]['code']);

        // NIST CSF : correspondance officielle inversée (sous-catégorie => mesures de l'Annexe A).
        $nist = array_column(FrameworkCatalogMeasures::measures(FrameworkSelection::NIST_CSF), null, 'code');
        $this->assertCount(count(NistCsfCatalogDefaults::SUBCATEGORIES), $nist);
        $this->assertContains('A.5.1', $nist['GV.PO-01']['annex_a']);
    }

    public function testUnknownKeyHasNoMeasures(): void
    {
        $this->assertSame([], FrameworkCatalogMeasures::measures('custom_3'));
        $this->assertSame([], FrameworkCatalogMeasures::measures(FrameworkSelection::ACN_IT));
    }
}

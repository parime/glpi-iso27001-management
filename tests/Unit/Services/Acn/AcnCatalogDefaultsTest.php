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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Acn;

use GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults as Catalog;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Issue #703 : catalogue des misure di sicurezza di base (déterminazione ACN 379907/2025).
 */
final class AcnCatalogDefaultsTest extends TestCase
{
    public function testAnnexesHaveTheOfficialCounts(): void
    {
        // Allegato 1 : 37 mesures, 87 points ; allegato 2 : 43 mesures, 116 points.
        self::assertCount(37, Catalog::measuresFor(Catalog::CATEGORY_IMPORTANT));
        self::assertCount(87, Catalog::pointsFor(Catalog::CATEGORY_IMPORTANT));
        self::assertCount(43, Catalog::measuresFor(Catalog::CATEGORY_ESSENTIAL));
        self::assertCount(116, Catalog::pointsFor(Catalog::CATEGORY_ESSENTIAL));
        self::assertCount(116, Catalog::allPoints());
        self::assertSame([], Catalog::pointsFor(''));
    }

    public function testEveryMeasureIsANistCsfSubcategoryWithCompleteTexts(): void
    {
        foreach (Catalog::MEASURES as $code => $measure) {
            self::assertArrayHasKey($code, NistCsfCatalogDefaults::SUBCATEGORIES, $code);
            self::assertArrayHasKey(Catalog::functionOf($code), Catalog::FUNCTIONS, $code);
            foreach (['it', 'en', 'fr'] as $lang) {
                self::assertNotSame('', trim($measure[$lang]), "$code $lang");
            }
            self::assertNotEmpty($measure['points'], $code);
            self::assertSame(range(1, count($measure['points'])), array_keys($measure['points']), "$code : points 1..n");
            foreach ($measure['points'] as $point => $entry) {
                foreach (['it', 'en', 'fr'] as $lang) {
                    self::assertNotSame('', trim($entry[$lang]), "$code punto $point $lang");
                }
            }
            foreach ($measure['nis2'] as $key) {
                self::assertArrayHasKey($key, Nis2CatalogDefaults::REQUIREMENTS, "$code -> NIS2 $key");
            }
        }
    }

    public function testEssentialOnlyPointsAndMeasures(): void
    {
        // Mesures propres à l'allegato 2.
        foreach (['ID.AM-03', 'PR.AT-02', 'PR.PS-01', 'PR.PS-03', 'PR.IR-03', 'RC.CO-03'] as $code) {
            self::assertNotContains($code, Catalog::measuresFor(Catalog::CATEGORY_IMPORTANT), $code);
            self::assertContains($code, Catalog::measuresFor(Catalog::CATEGORY_ESSENTIAL), $code);
        }
        // DE.CM-01 : points 1 à 3 pour tous, 4 à 7 pour les essentielles.
        self::assertTrue(Catalog::inScope('DE.CM-01', 2, Catalog::CATEGORY_IMPORTANT));
        self::assertFalse(Catalog::inScope('DE.CM-01', 4, Catalog::CATEGORY_IMPORTANT));
        self::assertTrue(Catalog::inScope('DE.CM-01', 7, Catalog::CATEGORY_ESSENTIAL));
        self::assertFalse(Catalog::inScope('DE.CM-01', 8, Catalog::CATEGORY_ESSENTIAL));
        self::assertSame([1, 2], Catalog::annexesOf('DE.CM-01', 2));
        self::assertSame([2], Catalog::annexesOf('DE.CM-01', 4));
    }

    public function testApprovalPointsAndReferences(): void
    {
        $approvals = [];
        foreach (Catalog::allPoints() as [$code, $point]) {
            if (Catalog::requiresApproval($code, $point)) {
                $approvals[] = Catalog::reference($code, $point);
            }
        }
        self::assertSame([
            'GV.RR-02, punto 1', 'GV.PO-01, punto 3', 'ID.RA-05, punto 3', 'ID.RA-06, punto 3',
            'ID.RA-08, punto 4', 'ID.IM-01, punto 1', 'ID.IM-04, punto 4', 'PR.AT-01, punto 2',
            'RS.MA-01, punto 2',
        ], $approvals);
        self::assertSame('DE.CM-01, punto 2', Catalog::reference('DE.CM-01', 2));
    }

    public function testAnnexAMappingComesFromTheNistCrosswalk(): void
    {
        foreach (array_keys(Catalog::MEASURES) as $code) {
            foreach (Catalog::annexAFor($code) as $annexA) {
                self::assertArrayHasKey($annexA, ControlCatalogDefaults::CONTROLS, "$code -> $annexA");
            }
        }
        self::assertContains('A.8.13', Catalog::annexAFor('PR.DS-11'));
    }

    public function testTextsFollowTheRequestedLanguageWithFallback(): void
    {
        self::assertSame('Backup', Catalog::measureTitle('PR.DS-11', 'it'));
        self::assertSame('Backups', Catalog::measureTitle('PR.DS-11', 'de'));
        self::assertSame('Sauvegardes', Catalog::measureTitle('PR.DS-11', 'fr'));
        self::assertSame('UNKNOWN', Catalog::measureTitle('UNKNOWN'));
        self::assertSame('', Catalog::pointSummary('PR.DS-11', 9));
    }
}

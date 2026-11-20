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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Recyf;

use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults as Catalog;
use PHPUnit\Framework\TestCase;

/**
 * Issue #599 : catalogue du Référentiel Cyber France (ReCyF), version de travail 2.5 de l'ANSSI.
 */
final class RecyfCatalogDefaultsTest extends TestCase
{
    public function testCatalogHasTheCountsOfVersion25(): void
    {
        // 20 objectifs, 152 mesures : 76 pour les entités importantes et essentielles, 76 de plus
        // pour les seules entités essentielles.
        self::assertSame('2.5', Catalog::VERSION);
        self::assertSame(range(1, 20), array_keys(Catalog::OBJECTIVES));
        self::assertCount(152, Catalog::codes());
        self::assertCount(76, Catalog::codesFor(Catalog::CATEGORY_IMPORTANT));
        self::assertCount(152, Catalog::codesFor(Catalog::CATEGORY_ESSENTIAL));
        self::assertSame([], Catalog::codesFor(''));
        self::assertSame(range(1, 15), Catalog::objectivesFor(Catalog::CATEGORY_IMPORTANT));
        self::assertSame(range(1, 20), Catalog::objectivesFor(Catalog::CATEGORY_ESSENTIAL));
    }

    public function testEveryMeasureHasAWellFormedNumberAndBothTexts(): void
    {
        $previous = [0, '', 0];
        foreach (Catalog::MEASURES as $code => $measure) {
            $code = (string) $code;
            self::assertMatchesRegularExpression('/^([1-9]|1\d|20)\.(?:[A-C]\.)?([1-9]|1[0-2])$/', $code);
            self::assertNotSame('', trim($measure['fr']), $code);
            self::assertNotSame('', trim($measure['en']), $code);
            self::assertStringNotContainsString("\u{2014}", $measure['fr'] . $measure['en'], $code);
            $objective = Catalog::objectiveOf($code);
            self::assertArrayHasKey($objective, Catalog::OBJECTIVES, $code);
            // Ordre du référentiel : objectifs croissants, sous-parties puis numéros croissants.
            $parts = explode('.', $code);
            $section = count($parts) === 3 ? $parts[1] : '';
            $number = (int) end($parts);
            $current = [$objective, $section, $number];
            self::assertGreaterThan(0, $current <=> $previous, $code . ' après ' . implode('.', $previous));
            $previous = $current;
            if ($section !== '') {
                self::assertSame($parts[0] . '.' . $section, Catalog::sectionOf($code), $code);
            }
        }
    }

    public function testReferenceCarriesTheOfficialSuffix(): void
    {
        self::assertSame('1.1-EI/EE', Catalog::reference('1.1'));
        self::assertSame('2.A.2-EE', Catalog::reference('2.A.2'));
        self::assertSame('10.B.7-EE', Catalog::reference('10.B.7'));
        self::assertSame('14.10-EE', Catalog::reference('14.10'));
        self::assertSame('10.B', Catalog::sectionOf('10.B.7'));
        self::assertSame('', Catalog::sectionOf('14.10'));
        self::assertSame(10, Catalog::objectiveOf('10.B.7'));
        self::assertSame(0, Catalog::objectiveOf('99.9'));
    }

    public function testObjectivesForEssentialEntitiesOnlyContainOnlyEssentialMeasures(): void
    {
        foreach (Catalog::codes() as $code) {
            $objective = Catalog::OBJECTIVES[Catalog::objectiveOf($code)];
            if ($objective['essential']) {
                self::assertTrue(Catalog::isEssentialOnly($code), $code);
                self::assertFalse(Catalog::inScope($code, Catalog::CATEGORY_IMPORTANT), $code);
            }
            self::assertTrue(Catalog::inScope($code, Catalog::CATEGORY_ESSENTIAL), $code);
        }
        self::assertTrue(Catalog::inScope('2.A.1', Catalog::CATEGORY_IMPORTANT));
        self::assertFalse(Catalog::inScope('2.A.2', Catalog::CATEGORY_IMPORTANT));
        self::assertFalse(Catalog::inScope('unknown', Catalog::CATEGORY_ESSENTIAL));
    }

    public function testNis2LinksPointToKnownRequirements(): void
    {
        foreach (Catalog::OBJECTIVES as $number => $objective) {
            self::assertNotSame('', trim($objective['fr']), (string) $number);
            self::assertNotSame('', trim($objective['en']), (string) $number);
            self::assertNotEmpty($objective['nis2'], (string) $number);
            foreach ($objective['nis2'] as $key) {
                self::assertArrayHasKey($key, Nis2CatalogDefaults::REQUIREMENTS, "objectif $number");
            }
        }
        self::assertArrayHasKey('21.2.b', Catalog::nis2For('12.1', 'en'));
        self::assertSame([], Catalog::nis2For('unknown'));
    }

    public function testTextsFollowTheLanguageWithEnglishFallback(): void
    {
        self::assertSame(Catalog::MEASURES['4.1']['fr'], Catalog::summary('4.1', 'fr'));
        self::assertSame(Catalog::MEASURES['4.1']['en'], Catalog::summary('4.1', 'it'));
        self::assertSame(Catalog::OBJECTIVES[13]['en'], Catalog::objectiveTitle(13, 'de'));
        self::assertSame(Catalog::SECTIONS['7.B']['fr'], Catalog::sectionTitle('7.B', 'fr'));
        self::assertSame('', Catalog::summary('unknown'));
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Dora;

use GlpiPlugin\Grcmanager\Services\Dora\RegisterRules;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use PHPUnit\Framework\TestCase;

/**
 * Issue #540 : formats du registre d'informations DORA.
 */
final class RegisterRulesTest extends TestCase
{
    private const BANK_LEI = '9999000EXEMPLEBANQ61';
    private const CLOUD_LEI = '9999000NUAGEDEMO0132';

    public function testLeiChecksum(): void
    {
        self::assertTrue(RegisterRules::isLei(self::BANK_LEI));
        self::assertTrue(RegisterRules::isLei(strtolower(self::CLOUD_LEI)), 'casse indifférente');
        self::assertFalse(RegisterRules::isLei('9999000EXEMPLEBANQ62'), 'clé de contrôle fausse');
        self::assertFalse(RegisterRules::isLei('9999000EXEMPLEBANQ6'), 'trop court');
        self::assertFalse(RegisterRules::isLei('9999000EXEMPLEBANQAB'), 'clé non numérique');
    }

    public function testCountriesAndCurrencies(): void
    {
        self::assertSame('FR', RegisterRules::country(' fr '));
        self::assertSame('', RegisterRules::country('XX'));
        self::assertSame('', RegisterRules::country('FRA'));
        self::assertSame(['FR', 'DE', 'IE'], RegisterRules::countries('fr, DE;ie fr zz'));
        self::assertSame('EUR', RegisterRules::currency('eur'));
        self::assertSame('', RegisterRules::currency('EURO'));
        self::assertCount(249, explode(' ', RegisterTemplates::COUNTRIES));
    }

    public function testServiceTypesKeepAnnexOrder(): void
    {
        self::assertSame(['S03', 'S19'], RegisterRules::serviceTypes(['S19', 's03', 'S99', 'S03']));
        self::assertSame(['S17'], RegisterRules::serviceTypes('S17'));
        self::assertCount(19, RegisterTemplates::SERVICE_TYPES);
    }

    public function testClosedListsAndNumbers(): void
    {
        self::assertSame(3, RegisterRules::option('3', RegisterTemplates::ARRANGEMENT_TYPES));
        self::assertSame(0, RegisterRules::option('4', RegisterTemplates::ARRANGEMENT_TYPES));
        self::assertSame(7, RegisterRules::option(7, RegisterTemplates::ALTERNATIVES), 'numérotation officielle');
        self::assertSame(90, RegisterRules::days('90'));
        self::assertNull(RegisterRules::days(''));
        self::assertNull(RegisterRules::days('-1'));
        self::assertSame(1234.5, RegisterRules::amount('1 234,50'));
        self::assertNull(RegisterRules::amount('abc'));
        self::assertSame('2026-02-28', RegisterRules::date('2026-02-28'));
        self::assertNull(RegisterRules::date('2026-02-30'));
        self::assertNull(RegisterRules::date('28/02/2026'));
    }

    public function testProviderCodeTypes(): void
    {
        self::assertSame('LEI', RegisterRules::codeType('lei'));
        self::assertSame('EUID', RegisterRules::codeType('EUID', 'FR'));
        self::assertSame('FR_VAT', RegisterRules::codeType('VAT', 'fr'));
        self::assertSame('', RegisterRules::codeType('VAT'), 'pays d\'émission requis');
        self::assertSame('', RegisterRules::codeType('SIRET', 'FR'));

        self::assertTrue(RegisterRules::isProviderCodeValid(self::CLOUD_LEI, 'LEI', 1));
        self::assertFalse(RegisterRules::isProviderCodeValid('123', 'LEI', 1), 'LEI invalide');
        self::assertFalse(RegisterRules::isProviderCodeValid('FR123', 'FR_VAT', 1), 'personne morale : LEI ou EUID');
        self::assertTrue(RegisterRules::isProviderCodeValid('FR123', 'FR_VAT', 2), 'personne physique : code national');
    }

    public function testEndDateAndImpact(): void
    {
        self::assertSame('2029-01-01', RegisterRules::endDate('2026-01-01', 36));
        self::assertSame(RegisterTemplates::OPEN_DATE, RegisterRules::endDate('2026-01-01', 0), 'sans fin');
        self::assertNull(RegisterRules::endDate(null, 12));

        self::assertSame(RegisterTemplates::IMPACT_NOT_ASSESSED, RegisterRules::impactFromBia(0));
        self::assertSame(1, RegisterRules::impactFromBia(1));
        self::assertSame(2, RegisterRules::impactFromBia(2));
        self::assertSame(3, RegisterRules::impactFromBia(4));
    }

    public function testCsvTextNeutralisesFormulas(): void
    {
        self::assertSame("'=1+1", RegisterRules::csvText('=1+1'));
        self::assertSame('Paie', RegisterRules::csvText('Paie'));
    }
}

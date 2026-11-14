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

use GlpiPlugin\Grcmanager\Services\Dora\RegisterBuilder;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterCompleteness;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterCsv;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RegisterFixture.php';

/**
 * Issue #540 : correspondance du registre avec les modèles B_01.01 à B_99.01, contrôle des champs
 * obligatoires et format CSV de l'export.
 */
final class RegisterBuilderTest extends TestCase
{
    public function testEveryTemplateIsBuiltWithItsOfficialColumns(): void
    {
        $rows = RegisterBuilder::build(RegisterFixture::register());

        self::assertSame(array_keys(RegisterTemplates::TEMPLATES), array_keys($rows));
        foreach ($rows as $template => $templateRows) {
            foreach ($templateRows as $row) {
                self::assertSame(
                    array_keys(RegisterTemplates::TEMPLATES[$template]['columns']),
                    array_keys($row),
                    $template
                );
            }
        }
        // B_06.01 n'a pas de colonne 0050 dans le texte officiel.
        self::assertArrayNotHasKey('c0050', RegisterTemplates::TEMPLATES['B_06.01']['columns']);
        self::assertSame([], $rows['B_01.03'], 'succursales : à remplir à la main');
        self::assertSame([], $rows['B_02.03']);
        self::assertSame([], $rows['B_03.03']);
    }

    public function testEntityAndArrangements(): void
    {
        $rows = RegisterBuilder::build(RegisterFixture::register());

        self::assertSame(RegisterFixture::BANK_LEI, $rows['B_01.01'][0]['c0010']);
        self::assertSame('credit institutions', $rows['B_01.01'][0]['c0040']);
        self::assertSame('9999-12-31', $rows['B_01.02'][0]['c0090'], 'entité jamais supprimée');
        self::assertSame('1500000000', $rows['B_01.02'][0]['c0110']);
        self::assertSame('EUR', $rows['B_01.02'][0]['c0100']);

        self::assertCount(2, $rows['B_02.01']);
        self::assertSame(['CTR-2026-01', 'standalone arrangement', '', 'EUR', '120000.50'], array_values($rows['B_02.01'][0]));
        self::assertSame(['CTR-2026-01', RegisterFixture::BANK_LEI], array_values($rows['B_03.01'][0]));
        self::assertSame(['CTR-2026-01', RegisterFixture::CLOUD_LEI, 'LEI'], array_values($rows['B_03.02'][0]));
        self::assertSame(RegisterTemplates::NATURES[2], $rows['B_04.01'][0]['c0030']);
    }

    public function testSpecificInformationHasOneRowPerFunctionServiceAndCountry(): void
    {
        $rows = RegisterBuilder::build(RegisterFixture::register());
        $first = array_values(array_filter($rows['B_02.02'], static fn (array $r): bool => $r['c0010'] === 'CTR-2026-01'));

        // 1 fonction x 2 types de services x 2 pays de stockage.
        self::assertCount(4, $first);
        self::assertSame(['S17', 'S17', 'S19', 'S19'], array_column($first, 'c0060'));
        self::assertSame(['FR', 'DE', 'FR', 'DE'], array_column($first, 'c0150'));
        self::assertSame(['FR', 'FR', 'FR', 'FR'], array_column($first, 'c0160'));
        self::assertSame('F5', $first[0]['c0050']);
        self::assertSame('2029-01-01', $first[0]['c0080']);
        self::assertSame('', $first[0]['c0090'], 'accord en cours : pas de motif de fin');
        self::assertSame('90', $first[0]['c0100']);
        self::assertSame('Yes', $first[0]['c0140']);
        self::assertSame('High', $first[0]['c0170']);
        self::assertSame('Full reliance', $first[0]['c0180']);

        $second = array_values(array_filter($rows['B_02.02'], static fn (array $r): bool => $r['c0010'] === 'CTR-2026-02'));
        self::assertCount(1, $second);
        self::assertSame('', $second[0]['c0170'], 'sans stockage : pas de sensibilité');
    }

    public function testSupplyChainProvidersAndFunctions(): void
    {
        $rows = RegisterBuilder::build(RegisterFixture::register());

        $chain = array_values(array_filter($rows['B_05.02'], static fn (array $r): bool => $r['c0010'] === 'CTR-2026-01'));
        self::assertCount(4, $chain, 'prestataire direct et sous-traitant, pour chaque type de service');
        self::assertSame(['1', '', ''], [$chain[0]['c0050'], $chain[0]['c0060'], $chain[0]['c0070']]);
        self::assertSame(
            [RegisterFixture::HOSTING_LEI, '2', RegisterFixture::CLOUD_LEI, 'LEI'],
            [$chain[1]['c0030'], $chain[1]['c0050'], $chain[1]['c0060'], $chain[1]['c0070']]
        );

        self::assertCount(2, $rows['B_05.01']);
        $byCode = array_column($rows['B_05.01'], null, 'c0010');
        $cloud = $byCode[RegisterFixture::CLOUD_LEI];
        self::assertSame('120000.50', $cloud['c0100'], 'coût annuel : somme de ses accords');
        self::assertSame('Nuage Démo SAS', $cloud['c0060'], 'nom latin : repris du nom légal');
        self::assertSame([RegisterFixture::CLOUD_LEI, 'LEI'], [$cloud['c0110'], $cloud['c0120']], 'hors groupe');
        $hosting = $byCode[RegisterFixture::HOSTING_LEI];
        self::assertSame(['FR12345678901', 'VAT', '5000'], [$hosting['c0030'], $hosting['c0040'], $hosting['c0100']]);

        $functions = array_column($rows['B_06.01'], null, 'c0010');
        self::assertSame(['F5', 'F6'], array_keys($functions));
        self::assertSame('Yes', $functions['F5']['c0060']);
        self::assertSame("'=Interruption bloquante", $functions['F5']['c0070'], 'formule neutralisée');
        self::assertSame(['4', '1', 'High'], [$functions['F5']['c0090'], $functions['F5']['c0100'], $functions['F5']['c0110']]);
    }

    public function testAssessmentOnlyForCriticalFunctions(): void
    {
        $rows = RegisterBuilder::build(RegisterFixture::register());

        self::assertCount(2, $rows['B_07.01'], 'un accord critique, deux types de services');
        $row = $rows['B_07.01'][0];
        self::assertSame('CTR-2026-01', $row['c0010']);
        self::assertSame('Highly complex substitutability', $row['c0050']);
        self::assertStringStartsWith('Difficulties', $row['c0060']);
        self::assertSame('9999-12-31', $row['c0070'], 'aucun audit');
        self::assertSame(['Yes', 'Highly complex', 'High', 'Yes', 'Autre nuage fictif'], [
            $row['c0080'], $row['c0090'], $row['c0100'], $row['c0110'], $row['c0120'],
        ]);
        self::assertCount(19, $rows['B_99.01']);
    }

    public function testEndedArrangementReportsItsReason(): void
    {
        $register = RegisterFixture::register();
        $register['arrangements'][1]['end_date'] = '2026-06-30';
        $register['arrangements'][1]['termination_reason'] = 1;
        $rows = RegisterBuilder::build($register);
        $second = array_values(array_filter($rows['B_02.02'], static fn (array $r): bool => $r['c0010'] === 'CTR-2026-02'));

        self::assertSame('Termination not for cause', $second[0]['c0090']);
    }

    public function testCompleteRegisterHasNoIssue(): void
    {
        $result = RegisterCompleteness::check(RegisterFixture::register());

        self::assertSame([], $result['issues']);
        self::assertSame(100, $result['rate']);
        self::assertGreaterThan(40, $result['checked']);
    }

    public function testMissingAndInvalidFieldsAreReported(): void
    {
        $register = RegisterFixture::register();
        $register['entity']['lei'] = '9999000EXEMPLEBANQ62';
        $register['entity']['authority'] = '';
        $register['arrangements'][0]['notice_entity'] = null;
        $register['arrangements'][0]['storage_countries'] = [];
        $register['arrangements'][0]['substitutability'] = 1;
        $register['arrangements'][0]['non_substitutable_reason'] = 0;
        $register['arrangements'][1]['functions'] = [];
        $register['arrangements'][1]['end_date'] = '2026-06-30';
        $register['providers'][11]['hq_country'] = '';
        $register['providers'][10]['code'] = 'FR123';
        $register['providers'][10]['code_type'] = 'FR_VAT';
        $register['functions'][5]['licensed_activity'] = '';

        $result = RegisterCompleteness::check($register);
        $found = array_map(
            static fn (array $i): string => $i['scope'] . ':' . $i['key'] . ':' . $i['field'] . ':' . $i['column'] . ':' . $i['kind'],
            $result['issues']
        );
        sort($found);

        self::assertSame([
            'arrangement:100:non_substitutable_reason:B_07.01.0060:missing',
            'arrangement:100:notice_entity:B_02.02.0100:missing',
            'arrangement:100:storage_countries:B_02.02.0150:missing',
            'arrangement:101:functions:B_02.02.0050:missing',
            'arrangement:101:termination_reason:B_02.02.0090:missing',
            'entity:0:authority:B_01.01.0050:missing',
            'entity:0:lei:B_01.01.0010:invalid',
            'function:5:licensed_activity:B_06.01.0020:missing',
            'provider:10:code:B_05.01.0010:invalid',
            'provider:11:hq_country:B_05.01.0080:missing',
        ], $found);
        self::assertLessThan(100, $result['rate']);
    }

    public function testNonCriticalArrangementNeedsFewerFields(): void
    {
        $register = RegisterFixture::register();
        $register['arrangements'] = [$register['arrangements'][1]];

        self::assertSame([], RegisterCompleteness::check($register)['issues'], 'pas de préavis exigé hors fonction critique');

        $register['functions'][6]['criticality'] = 1;
        $columns = array_column(RegisterCompleteness::check($register)['issues'], 'column');
        self::assertContains('B_02.02.0100', $columns);
        self::assertContains('B_07.01.0050', $columns);
    }

    public function testCsvFilesFollowTheXbrlCsvTableLayout(): void
    {
        $files = RegisterCsv::files(RegisterBuilder::build(RegisterFixture::register()));

        self::assertCount(count(RegisterTemplates::TEMPLATES), $files);
        self::assertArrayHasKey('b_02.02.csv', $files);
        self::assertStringStartsWith("c0010,c0020,c0030,c0040\r\n", $files['b_01.03.csv'], 'en-tête seul');
        self::assertSame("c0010,c0020,c0030,c0040\r\n", $files['b_01.03.csv']);
        self::assertStringNotContainsString("\u{FEFF}", $files['b_02.01.csv'], 'sans BOM');
        $lines = explode("\r\n", trim($files['b_02.01.csv']));
        self::assertSame('c0010,c0020,c0030,c0040,c0050', $lines[0]);
        self::assertSame('CTR-2026-01,standalone arrangement,,EUR,120000.50', $lines[1]);
        self::assertStringContainsString(',Nuage Démo SAS,', $files['b_05.01.csv'], 'UTF-8, sans guillemets inutiles');
        self::assertStringContainsString(
            '"Legal person, excluding individuals acting in business capacity"',
            $files['b_05.01.csv'],
            'virgule entourée de guillemets'
        );
        self::assertSame('', RegisterCsv::render('B_00.00', []));
    }
}

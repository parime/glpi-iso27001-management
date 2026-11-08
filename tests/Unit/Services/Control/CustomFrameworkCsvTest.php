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

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use PHPUnit\Framework\TestCase;

/**
 * Issue #537 : lecture du fichier d'un référentiel personnalisé.
 */
final class CustomFrameworkCsvTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function annexA(): array
    {
        return array_keys(ControlCatalogDefaults::CONTROLS);
    }

    public function testSemicolonFileWithBomAndFrenchHeaders(): void
    {
        $csv = "\xEF\xBB\xBFCode;Thème;Intitulé;Description;Annexe A\r\n"
            . "DEMO-01;Gouvernance;Politique approuvée;Revue annuelle;A.5.1, A.5.4\r\n"
            . "DEMO-02;Accès;Double authentification;;8.5\r\n";
        $parsed = CustomFrameworkCsv::parse($csv, $this->annexA());

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('DEMO-01', $parsed['rows'][0]['code']);
        $this->assertSame('Gouvernance', $parsed['rows'][0]['theme']);
        $this->assertSame(['A.5.1', 'A.5.4'], $parsed['rows'][0]['annex_a']);
        $this->assertSame(['A.8.5'], $parsed['rows'][1]['annex_a']);
        $this->assertSame(3, $parsed['rows'][1]['line']);
    }

    public function testCommaSeparatorAndQuotedMultilineField(): void
    {
        $csv = "code,title,description,annex_a\n"
            . "R1,\"Title, with comma\",\"First line\nsecond line\",\"A.5.1 A.5.2\"\n"
            . "R2,Other,,\n";
        $parsed = CustomFrameworkCsv::parse($csv, $this->annexA());

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Title, with comma', $parsed['rows'][0]['title']);
        $this->assertSame("First line\nsecond line", $parsed['rows'][0]['description']);
        $this->assertSame(['A.5.1', 'A.5.2'], $parsed['rows'][0]['annex_a']);
        // La ligne physique 4 suit le champ sur deux lignes.
        $this->assertSame(4, $parsed['rows'][1]['line']);
        $this->assertSame([], $parsed['rows'][1]['annex_a']);
    }

    public function testRejectedLinesCarryTheirNumberAndReason(): void
    {
        $csv = "code;theme;title;description;annex_a\n"
            . "OK-1;T;Valid;;A.5.1\n"
            . ";T;No code;;\n"
            . "OK-2;T;;;\n"
            . "ok-1;T;Duplicate in another case;;\n"
            . "OK-3;T;Unknown control;;A.5.1, A.99.9\n"
            . "\n"
            . "OK-4;T;Valid again;;\n";
        $parsed = CustomFrameworkCsv::parse($csv, $this->annexA());

        $this->assertSame(['OK-1', 'OK-4'], array_column($parsed['rows'], 'code'));
        $this->assertSame(
            [
                [3, CustomFrameworkCsv::ERROR_MISSING_CODE],
                [4, CustomFrameworkCsv::ERROR_MISSING_TITLE],
                [5, CustomFrameworkCsv::ERROR_DUPLICATE_CODE],
                [6, CustomFrameworkCsv::ERROR_UNKNOWN_ANNEX_A],
            ],
            array_map(static fn (array $e): array => [$e['line'], $e['reason']], $parsed['errors'])
        );
        $this->assertSame('2', $parsed['errors'][2]['detail']);
        $this->assertSame('A.99.9', $parsed['errors'][3]['detail']);
        $this->assertSame(8, $parsed['rows'][1]['line']);
    }

    public function testMissingColumnsAndEmptyFile(): void
    {
        $this->assertSame(
            CustomFrameworkCsv::ERROR_MISSING_COLUMNS,
            CustomFrameworkCsv::parse("foo;bar\n1;2\n", $this->annexA())['errors'][0]['reason']
        );
        $this->assertSame(CustomFrameworkCsv::ERROR_EMPTY, CustomFrameworkCsv::parse("\xEF\xBB\xBF\n \n", [])['errors'][0]['reason']);
    }

    public function testTooLongCodeAndRowLimit(): void
    {
        $csv = "code;title\n" . str_repeat('X', CustomFrameworkCsv::MAX_CODE_LENGTH + 1) . ";Too long\n";
        $this->assertSame(
            CustomFrameworkCsv::ERROR_CODE_TOO_LONG,
            CustomFrameworkCsv::parse($csv, [])['errors'][0]['reason']
        );

        $csv = "code;title\n";
        for ($i = 0; $i <= CustomFrameworkCsv::MAX_ROWS; $i++) {
            $csv .= "C$i;T\n";
        }
        $parsed = CustomFrameworkCsv::parse($csv, []);
        $this->assertCount(CustomFrameworkCsv::MAX_ROWS, $parsed['rows']);
        $this->assertSame(CustomFrameworkCsv::ERROR_TOO_MANY_ROWS, $parsed['errors'][0]['reason']);
    }

    public function testAnnexACodesAreNormalised(): void
    {
        $this->assertSame(['A.5.1', 'A.8.5', 'A.5.15'], CustomFrameworkCsv::splitAnnexA(' 5.1 | a.8.5 ;A5.15, 5.1'));
        $this->assertSame([], CustomFrameworkCsv::splitAnnexA(''));
    }

    public function testTemplateIsReadBackWithoutError(): void
    {
        $template = CustomFrameworkCsv::template([
            ['GRP-01', 'Gouvernance', 'Politique; approuvée', "Texte \"cité\"", 'A.5.1, A.5.4'],
        ]);
        $this->assertStringStartsWith("\xEF\xBB\xBFcode;theme;title;description;annex_a\r\n", $template);
        $parsed = CustomFrameworkCsv::parse($template, $this->annexA());
        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Politique; approuvée', $parsed['rows'][0]['title']);
        $this->assertSame('Texte "cité"', $parsed['rows'][0]['description']);
    }
}

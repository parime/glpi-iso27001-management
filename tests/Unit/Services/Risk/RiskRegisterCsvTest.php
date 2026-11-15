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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterCsv;
use PHPUnit\Framework\TestCase;

/**
 * Issue #602 : lecture du fichier d'un registre des risques (données fictives).
 */
final class RiskRegisterCsvTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function lookups(): array
    {
        return [
            'users'    => [
                'alice.demo'            => [11],
                'alice.demo@example.org' => [11],
                'alice demo'            => [11],
                'bob martin'            => [12, 13],
            ],
            'controls' => ['A.5.1', 'A.5.19', 'A.8.5', 'A.8.13'],
            'assets'   => [
                'srv-demo-01' => ['Computer:5'],
                'inv-0042'    => ['Computer:5'],
                'nas-demo'    => ['NetworkEquipment:7', 'Computer:9'],
            ],
            'labels'   => ['category' => ['Kategorie Technik' => 'technical']],
        ];
    }

    public function testFrenchSemicolonFileWithBomAndTolerantValues(): void
    {
        $csv = "\xEF\xBB\xBFRéférence;Titre;Catégorie;Probabilité;Gravité;Décision de traitement;Propriétaire;"
            . "Date de revue;Statut;Mesures;Actifs\r\n"
            . "R-01;Vol d'un portable;Technique;3;Élevé;Réduire;alice.demo@example.org;31/12/2026;En cours;"
            . "A.8.5 a.5.1;SRV-DEMO-01, inv-0042\r\n"
            . "R-02;Panne du site;physique;Rare;4 - Critique;accepter;Alice Demo;2026-06-30;;;\r\n";

        $parsed = RiskRegisterCsv::parse($csv, $this->lookups());

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $first = $parsed['rows'][0];
        $this->assertSame(2, $first['line']);
        $this->assertSame('R-01', $first['reference']);
        $this->assertSame([
            'title'        => "Vol d'un portable",
            'category'     => 'technical',
            'probability'  => 'probable',
            'impact'       => 'high',
            'treatment'    => 'mitigate',
            'status'       => 'in_treatment',
            'users_id'     => 11,
            'review_date'  => '2026-12-31',
            'annex_a'      => ['A.5.1', 'A.8.5'],
            'assets'       => ['Computer:5'],
        ], $first['values']);
        $second = $parsed['rows'][1]['values'];
        $this->assertSame('physical', $second['category']);
        $this->assertSame('rare', $second['probability']);
        $this->assertSame('critical', $second['impact']);
        $this->assertSame('accept', $second['treatment']);
        $this->assertArrayNotHasKey('status', $second, 'Une cellule vide ne dit rien.');
        $this->assertContains('status', $parsed['columns']);
    }

    public function testCommaSeparatedEnglishFileWithQuotedMultilineField(): void
    {
        $csv = "reference,title,description,likelihood,impact,owner\n"
            . "EXT-9,\"Phishing, targeted\",\"Line one\nline two\",likely,major,alice.demo\n"
            . "EXT-10,Lost badge,,unlikely,minor,\n";

        $parsed = RiskRegisterCsv::parse($csv, $this->lookups());

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Phishing, targeted', $parsed['rows'][0]['title']);
        $this->assertSame("Line one\nline two", $parsed['rows'][0]['values']['description']);
        $this->assertSame('probable', $parsed['rows'][0]['values']['probability']);
        $this->assertSame('high', $parsed['rows'][0]['values']['impact']);
        $this->assertSame(4, $parsed['rows'][1]['line'], 'Le numéro de ligne suit les retours à la ligne.');
        $this->assertSame('rare', $parsed['rows'][1]['values']['probability']);
        $this->assertSame('low', $parsed['rows'][1]['values']['impact']);
    }

    public function testRejectedLinesCarryLineNumberAndReason(): void
    {
        $csv = "reference;title;probability;impact;treatment;owner;review_date;annex_a;assets;category\n"
            . ";Sans référence;;;;;;;;\n"
            . "R-1;;;;;;;;;\n"
            . "R-2;Probabilité inconnue;souvent;;;;;;;\n"
            . "R-3;Propriétaire inconnu;;;;nobody.demo;;;;\n"
            . "R-4;Propriétaire ambigu;;;;Bob Martin;;;;\n"
            . "R-5;Traitement inconnu;;;ignorer;;;;;\n"
            . "R-6;Mesure inconnue;;;;;;A.42.1;;\n"
            . "R-7;Actif inconnu;;;;;;;PC-INCONNU;\n"
            . "R-8;Actif ambigu;;;;;;;NAS-DEMO;\n"
            . "R-9;Date invalide;;;;;31/02/2026;;;\n"
            . "R-10;Valide;;;;;;;;Kategorie Technik\n"
            . "r-10;Doublon;;;;;;;;\n"
            . "R-11;Note hors échelle;7;;;;;;;\n";

        $parsed = RiskRegisterCsv::parse($csv, $this->lookups());

        $reasons = array_map(
            static fn (array $error): string => $error['line'] . ':' . $error['reason'],
            $parsed['errors']
        );
        $this->assertSame([
            '2:' . RiskRegisterCsv::ERROR_MISSING_REFERENCE,
            '3:' . RiskRegisterCsv::ERROR_MISSING_TITLE,
            '4:' . RiskRegisterCsv::ERROR_UNKNOWN_VALUE,
            '5:' . RiskRegisterCsv::ERROR_UNKNOWN_OWNER,
            '6:' . RiskRegisterCsv::ERROR_AMBIGUOUS_OWNER,
            '7:' . RiskRegisterCsv::ERROR_UNKNOWN_VALUE,
            '8:' . RiskRegisterCsv::ERROR_UNKNOWN_ANNEX_A,
            '9:' . RiskRegisterCsv::ERROR_UNKNOWN_ASSET,
            '10:' . RiskRegisterCsv::ERROR_AMBIGUOUS_ASSET,
            '11:' . RiskRegisterCsv::ERROR_INVALID_DATE,
            '13:' . RiskRegisterCsv::ERROR_DUPLICATE_REFERENCE,
            '14:' . RiskRegisterCsv::ERROR_UNKNOWN_VALUE,
        ], $reasons);
        $this->assertSame('probability' . "\x1F" . 'souvent', $parsed['errors'][2]['detail']);
        $this->assertSame('12', $parsed['errors'][10]['detail']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('technical', $parsed['rows'][0]['values']['category'], 'Libellé de la langue de l\'écran.');
    }

    public function testMissingColumnsAndEmptyFile(): void
    {
        $this->assertSame(
            RiskRegisterCsv::ERROR_MISSING_COLUMNS,
            RiskRegisterCsv::parse("title;impact\nA;low\n")['errors'][0]['reason']
        );
        $this->assertSame(RiskRegisterCsv::ERROR_EMPTY, RiskRegisterCsv::parse("\n\n")['errors'][0]['reason']);
    }

    public function testNumericScales(): void
    {
        $this->assertSame('rare', RiskRegisterCsv::mapValue('probability', '1', 5));
        $this->assertSame('possible', RiskRegisterCsv::mapValue('probability', '2', 5));
        $this->assertSame('possible', RiskRegisterCsv::mapValue('probability', '3', 5));
        $this->assertSame('probable', RiskRegisterCsv::mapValue('probability', '4', 5));
        $this->assertSame('certain', RiskRegisterCsv::mapValue('probability', '5', 5));
        $this->assertSame('low', RiskRegisterCsv::mapValue('impact', '1', 3));
        $this->assertSame('medium', RiskRegisterCsv::mapValue('impact', '2', 3));
        $this->assertSame('critical', RiskRegisterCsv::mapValue('impact', '3', 3));
        $this->assertSame('high', RiskRegisterCsv::mapValue('residual_impact', '7', 10));
        $this->assertSame('medium', RiskRegisterCsv::mapValue('impact', '2.0'));
        $this->assertNull(RiskRegisterCsv::mapValue('impact', '0'));
        $this->assertNull(RiskRegisterCsv::mapValue('impact', '5'));
        $this->assertNull(RiskRegisterCsv::mapValue('status', '2'));
        $this->assertSame('', RiskRegisterCsv::mapValue('treatment', 'Aucune décision'));
        $this->assertSame('third_party', RiskRegisterCsv::mapValue('category', 'Fournisseur'));
        $this->assertSame('closed', RiskRegisterCsv::mapValue('status', 'Clôturé'));
    }

    public function testWindows1252FileIsConverted(): void
    {
        $csv = mb_convert_encoding("référence;titre;gravité\nR-1;Accès réseau;élevée\n", 'Windows-1252', 'UTF-8');
        $this->assertFalse(mb_check_encoding($csv, 'UTF-8'));

        $parsed = RiskRegisterCsv::parse(RiskRegisterCsv::toUtf8($csv));

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Accès réseau', $parsed['rows'][0]['title']);
        $this->assertSame('high', $parsed['rows'][0]['values']['impact']);
    }

    public function testDates(): void
    {
        $this->assertSame('2026-03-01', RiskRegisterCsv::parseDate('01/03/2026'));
        $this->assertSame('2026-03-01', RiskRegisterCsv::parseDate('1.3.2026'));
        $this->assertSame('2026-03-01', RiskRegisterCsv::parseDate('2026-03-01 00:00:00'));
        $this->assertNull(RiskRegisterCsv::parseDate('2026-13-01'));
        $this->assertNull(RiskRegisterCsv::parseDate('next year'));
    }

    public function testExportIsProtectedAgainstFormulasAndReadsBackIdentically(): void
    {
        $header = array_merge(RiskRegisterCsv::COLUMNS, RiskRegisterCsv::COMPUTED_COLUMNS);
        $row = [
            'R-1', '=HYPERLINK("http://example.org")', "Deux lignes;\n\"citées\"", 'technical', 'probable',
            'high', 'rare', 'low', 'mitigate', '-moins', 'alice.demo', '2026-12-31', 'identified',
            'A.5.1, A.8.5', 'SRV-DEMO-01', 'high', 'low',
        ];

        $content = RiskRegisterCsv::build($header, [$row]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString(";\"'=HYPERLINK(\"\"http://example.org\"\")\";", $content);
        $this->assertStringContainsString(";'-moins;", $content);

        $parsed = RiskRegisterCsv::parse($content, $this->lookups());
        $this->assertSame([], $parsed['errors']);
        $values = $parsed['rows'][0]['values'];
        $this->assertSame('=HYPERLINK("http://example.org")', $values['title']);
        $this->assertSame("Deux lignes;\n\"citées\"", $values['description']);
        $this->assertSame('-moins', $values['justification']);
        $this->assertSame(['A.5.1', 'A.8.5'], $values['annex_a']);
        $this->assertSame(['Computer:5'], $values['assets']);
        $this->assertSame('rare', $values['residual_probability']);
        $this->assertNotContains('risk_level', $parsed['columns'], 'Colonnes calculées ignorées.');
    }
}

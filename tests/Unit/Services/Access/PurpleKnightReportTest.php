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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AdAuditRules;
use GlpiPlugin\Grcmanager\Services\Access\PingCastleReport;
use GlpiPlugin\Grcmanager\Services\Access\PurpleKnightReport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #835, lot 2 : lecture de l'export CSV de Purple Knight (exemple fictif, fichiers piégés).
 */
final class PurpleKnightReportTest extends TestCase
{
    private static function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/purpleknight-example.csv');
    }

    public function testParsesTheSampleExport(): void
    {
        $report = PurpleKnightReport::parse(self::sample());

        $this->assertSame('', $report['error']);
        $this->assertSame(AdAuditRules::TOOL_PURPLEKNIGHT, $report['tool']);
        $this->assertSame('exemple.test', $report['domain']);
        $this->assertSame('4.6', $report['engine_version']);
        $this->assertStringStartsWith('2026-03-20', (string) $report['report_date']);
        $this->assertSame(9, $report['checks_total'], 'L\'indicateur « Not relevant » n\'est pas compté.');
        $this->assertSame(3, $report['checks_passed']);
        $this->assertCount(6, $report['rules']);
        $this->assertNotNull($report['native_score']);
        $this->assertSame(100 - $report['native_score'], $report['scores']['global']);
        $this->assertNull($report['scores']['stale'], 'Pas de score par catégorie de PingCastle.');

        $first = $report['rules'][0];
        $this->assertSame(40, $first['points'], 'Indicateurs critiques en premier.');
        $this->assertSame(AdAuditRules::SEVERITY_HIGH, AdAuditRules::severity($first['points']));
        $byId = array_column($report['rules'], null, 'rule_id');
        $dcsync = $byId['Non-default principals with DCSync rights on the domain'];
        $this->assertSame(AdAuditRules::CATEGORY_DELEGATION, $dcsync['category']);
        $this->assertSame('IOC · Critical', $dcsync['model']);
        $this->assertStringContainsString('2 non-default principals', $dcsync['rationale']);
        $this->assertSame(
            AdAuditRules::CATEGORY_KERBEROS,
            $byId['Kerberos krbtgt account with old password']['category']
        );
        $this->assertSame(
            AdAuditRules::CATEGORY_INFRASTRUCTURE,
            $byId['LDAP signing is not required on domain controllers']['category']
        );
        $this->assertFalse($report['secrets']);
        $this->assertFalse($report['truncated']);
    }

    public function testSemicolonBomAndUtf16AreRead(): void
    {
        $csv = "\xEF\xBB\xBFIndicator Name;Severity;Status;Domain\n"
            . "Accounts with SPN and admin rights;Critical;Failed;ad.exemple.test\n"
            . "Other check;Warning;Passed;ad.exemple.test\n";
        $report = PurpleKnightReport::parse($csv);
        $this->assertSame('', $report['error']);
        $this->assertSame('ad.exemple.test', $report['domain']);
        $this->assertSame(2, $report['checks_total']);
        $this->assertCount(1, $report['rules']);
        $this->assertSame(AdAuditRules::CATEGORY_OTHER, $report['rules'][0]['category']);

        $utf16 = "\xFF\xFE"
            . mb_convert_encoding("Indicator Name,Status\nFictitious check,Failed\n", 'UTF-16LE', 'UTF-8');
        $this->assertCount(1, PurpleKnightReport::parse($utf16)['rules']);
    }

    public function testTrappedFiles(): void
    {
        $this->assertSame(PingCastleReport::ERROR_EMPTY, PurpleKnightReport::parse('  ')['error']);
        $this->assertSame(
            PurpleKnightReport::ERROR_NOT_PURPLEKNIGHT,
            PurpleKnightReport::parse("account,password\nadmin,Secret123\n")['error']
        );
        $this->assertSame(
            PingCastleReport::ERROR_TOO_LARGE,
            PurpleKnightReport::parse(str_repeat('a', PurpleKnightReport::MAX_BYTES + 1))['error']
        );
        $this->assertSame(
            PingCastleReport::ERROR_INVALID,
            PurpleKnightReport::parse("Indicator Name\n\0bin")['error']
        );

        // Balises et mots de passe dans les cellules : retirés, jamais gardés.
        $csv = "Indicator Name,Status,Result Message,Description\n"
            . "\"<script>alert(1)</script>GPP check\",Failed,\"password: Fictif-835!\",Passwords in group policies\n";
        $report = PurpleKnightReport::parse($csv);
        $this->assertTrue($report['secrets']);
        $encoded = (string) json_encode($report);
        $this->assertStringNotContainsString('Fictif-835', $encoded);
        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertSame('Passwords in group policies', $report['rules'][0]['rationale']);

        // Nombre de lignes borné.
        $many = "Indicator Name,Status\n" . str_repeat("Check,Failed\n", 10)
            . implode('', array_map(
                static fn (int $i): string => "Check $i,Passed\n",
                range(1, PurpleKnightReport::MAX_ROWS)
            ));
        $long = PurpleKnightReport::parse($many);
        $this->assertTrue($long['truncated']);
        $this->assertSame(PurpleKnightReport::MAX_ROWS, $long['checks_total']);
        $this->assertCount(1, $long['rules'], 'Un même indicateur n\'est gardé qu\'une fois.');
    }
}

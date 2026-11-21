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

use GlpiPlugin\Grcmanager\Services\Access\AdAuditReports;
use GlpiPlugin\Grcmanager\Services\Access\AdAuditRules;
use GlpiPlugin\Grcmanager\Services\Access\CloudPostureReport;
use GlpiPlugin\Grcmanager\Services\Access\PingCastleReport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #835, lot 2 : lecture des résultats JSON de Maester et de ScubaGear (exemples fictifs,
 * fichiers piégés), et reconnaissance de l'outil à l'import.
 */
final class CloudPostureReportTest extends TestCase
{
    private static function sample(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/' . $name);
    }

    public function testParsesMaesterResults(): void
    {
        $report = CloudPostureReport::parse(self::sample('maester-example.json'));

        $this->assertSame('', $report['error']);
        $this->assertSame(AdAuditRules::TOOL_MAESTER, $report['tool']);
        $this->assertSame('00000000-0000-4000-8000-000000000835', $report['domain']);
        $this->assertSame('Exemple fictif', $report['netbios']);
        $this->assertSame('1.1.0', $report['engine_version']);
        $this->assertStringStartsWith('2026-03-21', (string) $report['report_date']);
        $this->assertSame(9, $report['checks_total'], 'Le test ignoré n\'est pas compté.');
        $this->assertSame(5, $report['checks_passed']);
        $this->assertSame(44, $report['scores']['global'], '4 contrôles en échec sur 9.');
        $this->assertSame(56, $report['native_score']);
        $this->assertCount(4, $report['rules']);

        $byId = array_column($report['rules'], null, 'rule_id');
        $this->assertSame(40, $byId['MT.1005']['points']);
        $this->assertSame(AdAuditRules::CATEGORY_IDENTITY, $byId['MT.1005']['category']);
        $this->assertContains('A.8.5', AdAuditRules::controls($byId['MT.1005']['category'], 'MT.1005'));
        $this->assertSame(AdAuditRules::CATEGORY_CLOUD, $byId['CISA.MS.EXO.4.1']['category']);
        $this->assertSame(10, $byId['CISA.MS.EXO.4.1']['points'], 'Sans gravité : moyenne.');
        $this->assertSame('Conditional Access · Critical', $byId['MT.1005']['model']);

        $encoded = (string) json_encode($report);
        $this->assertStringNotContainsString('auditeur', $encoded, 'Ni le compte ni la machine.');
        $this->assertStringNotContainsString('Fictitious detail', $encoded, 'Pas le détail des résultats.');
    }

    public function testParsesScubaGearResults(): void
    {
        $report = CloudPostureReport::parse(self::sample('scubagear-example.json'));

        $this->assertSame('', $report['error']);
        $this->assertSame(AdAuditRules::TOOL_SCUBAGEAR, $report['tool']);
        $this->assertSame('exemple.onmicrosoft.test', $report['domain']);
        $this->assertSame('Exemple fictif', $report['netbios']);
        $this->assertSame('1.6.0', $report['engine_version']);
        $this->assertSame(7, $report['checks_total'], 'Le contrôle manuel (N/A) n\'est pas compté.');
        $this->assertSame(3, $report['checks_passed']);
        $this->assertCount(4, $report['rules']);

        $byId = array_column($report['rules'], null, 'rule_id');
        $this->assertSame(30, $byId['MS.AAD.3.1v1']['points'], 'Exigence « Shall » en échec : élevée.');
        $this->assertSame(10, $byId['MS.AAD.3.5v1']['points'], 'Recommandation en avertissement : moyenne.');
        $this->assertSame(AdAuditRules::CATEGORY_IDENTITY, $byId['MS.AAD.7.1v1']['category']);
        $this->assertSame(AdAuditRules::CATEGORY_CLOUD, $byId['MS.EXO.4.1v1']['category']);
        $this->assertContains('A.5.23', AdAuditRules::controls(AdAuditRules::CATEGORY_CLOUD, 'MS.EXO.4.1v1'));
        $this->assertStringStartsWith('AAD · Highly Privileged User Access', $byId['MS.AAD.7.1v1']['model']);

        $encoded = (string) json_encode($report);
        $this->assertStringNotContainsString('Fictitious raw account', $encoded, 'La section Raw n\'est pas lue.');
        $this->assertStringNotContainsString('policy-indicators', $encoded);
        $this->assertStringNotContainsString('Automated Check', $encoded);
    }

    public function testTrappedJson(): void
    {
        $this->assertSame(PingCastleReport::ERROR_INVALID, CloudPostureReport::parse('{"Tests": [')['error']);
        $deep = str_repeat('[', CloudPostureReport::MAX_DEPTH + 5) . str_repeat(']', CloudPostureReport::MAX_DEPTH + 5);
        $this->assertSame(PingCastleReport::ERROR_INVALID, CloudPostureReport::parse('{"a":' . $deep . '}')['error']);
        $this->assertSame(CloudPostureReport::ERROR_NOT_CLOUD, CloudPostureReport::parse('[1, 2]')['error']);
        $this->assertSame(CloudPostureReport::ERROR_NOT_CLOUD, CloudPostureReport::parse('{"name": "x"}')['error']);
        $this->assertSame(
            PingCastleReport::ERROR_TOO_LARGE,
            CloudPostureReport::parse('{"a":"' . str_repeat('a', CloudPostureReport::MAX_BYTES) . '"}')['error']
        );

        // Valeurs d'un mauvais type, balises : ignorées ou retirées.
        $json = (string) json_encode([
            'TenantId' => ['not', 'a', 'string'],
            'Tests'    => [
                'oops',
                ['Name' => '<img src=x onerror=alert(1)>MT.9: Fictitious', 'Result' => 'Failed', 'Tag' => [['x']]],
                ['Name' => 'MT.10: Fictitious', 'Result' => ['Failed']],
            ],
        ]);
        $report = CloudPostureReport::parse($json);
        $this->assertSame('', $report['error']);
        $this->assertSame('', $report['domain']);
        $this->assertSame(1, $report['checks_total']);
        $this->assertSame('MT.9', $report['rules'][0]['rule_id']);
        $this->assertStringNotContainsString('<img', (string) json_encode($report));
    }

    public function testDispatcherRecognisesEachTool(): void
    {
        $tools = [
            'pingcastle-example.xml'   => AdAuditRules::TOOL_PINGCASTLE,
            'purpleknight-example.csv' => AdAuditRules::TOOL_PURPLEKNIGHT,
            'maester-example.json'     => AdAuditRules::TOOL_MAESTER,
            'scubagear-example.json'   => AdAuditRules::TOOL_SCUBAGEAR,
        ];
        foreach ($tools as $file => $tool) {
            $report = AdAuditReports::parse(self::sample($file));
            $this->assertSame('', $report['error'], $file);
            $this->assertSame($tool, $report['tool'], $file);
            $this->assertArrayHasKey('checks_total', $report);
        }
        $this->assertSame(AdAuditReports::ERROR_UNKNOWN_FORMAT, AdAuditReports::parse("a,b\n1,2\n")['error']);
        $this->assertSame(PingCastleReport::ERROR_EMPTY, AdAuditReports::parse(" \n")['error']);
        $this->assertSame(
            PingCastleReport::ERROR_DOCTYPE,
            AdAuditReports::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "e">]><HealthcheckData/>')['error']
        );
    }
}

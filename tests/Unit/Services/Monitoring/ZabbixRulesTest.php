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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Monitoring;

use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #836 : règles pures de la supervision Zabbix (aucune base, aucun réseau).
 */
final class ZabbixRulesTest extends TestCase
{
    public function testApiUrlIsBuiltFromTheInterfaceAddress(): void
    {
        $this->assertSame(
            'https://zabbix.example.test/zabbix/api_jsonrpc.php',
            Rules::normalizeApiUrl('https://zabbix.example.test/zabbix/')
        );
        $this->assertSame(
            'https://zabbix.example.test/api_jsonrpc.php',
            Rules::normalizeApiUrl(' https://Zabbix.Example.test/api_jsonrpc.php ')
        );
        $this->assertSame(
            'https://zabbix.example.test:8443/zabbix/api_jsonrpc.php',
            Rules::normalizeApiUrl('https://zabbix.example.test:8443/zabbix/zabbix.php')
        );
        // http seulement si les adresses internes sont autorisées.
        $this->assertNull(Rules::normalizeApiUrl('http://zabbix.example.test/'));
        $this->assertSame('http://10.0.0.5/api_jsonrpc.php', Rules::normalizeApiUrl('http://10.0.0.5', true));
    }

    public function testApiUrlRefusesCredentialsParametersAndOtherSchemes(): void
    {
        $this->assertNull(Rules::normalizeApiUrl('https://user:secret@zabbix.example.test/'));
        $this->assertNull(Rules::normalizeApiUrl('https://zabbix.example.test/?auth=x'));
        $this->assertNull(Rules::normalizeApiUrl('https://zabbix.example.test/#x'));
        $this->assertNull(Rules::normalizeApiUrl('ftp://zabbix.example.test/'));
        $this->assertNull(Rules::normalizeApiUrl('https://zabbix.example.test/../etc'));
        $this->assertNull(Rules::normalizeApiUrl('https://zabbix.example.test/a b'));
        $this->assertNull(Rules::normalizeApiUrl('zabbix.example.test'));
        $this->assertNull(Rules::normalizeApiUrl(''));
    }

    public function testSettingsAreBounded(): void
    {
        $this->assertSame(
            ['scope' => Rules::SCOPE_CRITICAL_SERVERS, 'period_days' => 30],
            Rules::normalizeSettings(null)
        );
        $this->assertSame(
            ['scope' => Rules::SCOPE_ALL, 'period_days' => 90],
            Rules::normalizeSettings(['scope' => 'all', 'period_days' => '400'])
        );
        $this->assertSame(1, Rules::normalizeSettings(['period_days' => -3])['period_days']);
        $this->assertSame(Rules::SCOPE_CRITICAL_SERVERS, Rules::normalizeSettings(['scope' => 'x'])['scope']);
    }

    public function testSeverities(): void
    {
        $this->assertSame(5, Rules::normalizeSeverity('9'));
        $this->assertSame(0, Rules::normalizeSeverity('abc'));
        $this->assertTrue(Rules::isHigh(4));
        $this->assertTrue(Rules::isHigh(5));
        $this->assertFalse(Rules::isHigh(3));
        $this->assertSame('critical', Rules::incidentSeverity(5));
        $this->assertSame('major', Rules::incidentSeverity(4));
        $this->assertSame('minor', Rules::incidentSeverity(2));
    }

    public function testCapacityFromTagsThenFromTheName(): void
    {
        $tag = static fn (string $value): array => [['tag' => 'component', 'value' => $value]];
        $this->assertSame(Rules::CAPACITY_STORAGE, Rules::capacityKind('Anything', $tag('storage')));
        $this->assertSame(
            Rules::CAPACITY_MEMORY,
            Rules::capacityKind('Anything', [['tag' => 'Component', 'value' => 'memory']])
        );
        $this->assertSame(Rules::CAPACITY_CPU, Rules::capacityKind('Anything', $tag('cpu')));
        $this->assertSame(Rules::CAPACITY_STORAGE, Rules::capacityKind('/var: Disk space is critically low'));
        $this->assertSame(Rules::CAPACITY_MEMORY, Rules::capacityKind('High memory utilization'));
        $this->assertSame(Rules::CAPACITY_CPU, Rules::capacityKind('High CPU utilization'));
        $this->assertSame(Rules::CAPACITY_CPU, Rules::capacityKind('Load average is too high'));
        $this->assertNull(Rules::capacityKind('Unavailable by ICMP ping', $tag('network')));
    }

    public function testDurationAndAvailabilityMergeOverlaps(): void
    {
        $this->assertSame(600, Rules::duration(1000, 1600, 5000));
        $this->assertSame(4000, Rules::duration(1000, null, 5000));
        $this->assertSame(0, Rules::duration(5000, 1000, 6000));
        // Période de 10 000 s, arrêts [1000, 2000] et [1500, 2500] fusionnés, [9500, 11000] coupé à 10 000.
        $intervals = [[1000, 2000], [1500, 2500], [9500, 11000]];
        $this->assertSame(2000, Rules::downtime($intervals, 0, 10000));
        $this->assertSame(80.0, Rules::availability($intervals, 0, 10000));
        $this->assertSame(100.0, Rules::availability([], 0, 10000));
        $this->assertNull(Rules::availability([], 10, 10));
        // Arrêt avant la période : sans effet.
        $this->assertSame(100.0, Rules::availability([[0, 50]], 100, 200));
    }

    public function testRtoAndSlo(): void
    {
        $this->assertTrue(Rules::exceedsRto(5 * 3600, 4));
        $this->assertFalse(Rules::exceedsRto(4 * 3600, 4));
        $this->assertFalse(Rules::exceedsRto(100 * 3600, 0));
        $this->assertSame(Rules::SLI_BELOW, Rules::sliStatus(99.5, 99.9));
        $this->assertSame(Rules::SLI_OK, Rules::sliStatus(99.9, 99.9));
        $this->assertSame(Rules::SLI_UNKNOWN, Rules::sliStatus(null, 99.9));
    }

    public function testStatusAndSummary(): void
    {
        $this->assertSame(Rules::STATUS_MISSING, Rules::status(false, false));
        $this->assertSame(Rules::STATUS_DISABLED, Rules::status(true, false));
        $this->assertSame(Rules::STATUS_UNAVAILABLE, Rules::status(true, true, [1, 2]));
        $this->assertSame(Rules::STATUS_MONITORED, Rules::status(true, true, [1, 0]));
        $summary = Rules::summary([
            ['status' => Rules::STATUS_MONITORED],
            ['status' => Rules::STATUS_UNAVAILABLE],
            ['status' => Rules::STATUS_DISABLED],
            ['status' => Rules::STATUS_MISSING],
        ]);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(50, $summary['rate']);
        $this->assertSame(2, $summary['uncovered']);
        $this->assertNull(Rules::summary([])['rate']);
    }

    public function testCriticalAndConcerned(): void
    {
        $this->assertTrue(Rules::isCritical('', 'high', '', false));
        $this->assertTrue(Rules::isCritical('', '', '', true));
        $this->assertFalse(Rules::isCritical('medium', 'low', '', false));
        $this->assertTrue(Rules::isConcerned(Rules::SCOPE_CRITICAL_SERVERS, false, true, false));
        $this->assertFalse(Rules::isConcerned(Rules::SCOPE_CRITICAL, false, true, true));
        $this->assertTrue(Rules::isConcerned(Rules::SCOPE_ALL, false, false, false));
    }

    public function testMatchBySerialNameShortNameThenIp(): void
    {
        $bySerial = ['sn-1234' => 'Computer:1'];
        $byName = [
            'srv-web01' => 'Computer:2', 'srv-web01.example.test' => 'Computer:2', 'fw01' => 'NetworkEquipment:3',
        ];
        $byIp = ['192.0.2.10' => 'Computer:4'];
        $match = static fn (array $names, array $ips = [], array $dns = []): ?string
            => Rules::match('', $names, $ips, $dns, $bySerial, $byName, $byIp);
        $this->assertSame('Computer:1', Rules::match('SN-1234', ['srv-web01'], [], [], $bySerial, $byName, $byIp));
        $this->assertSame('Computer:2', $match(['SRV-WEB01.example.test']));
        $this->assertSame('Computer:2', $match(['Web server', 'srv-web01.other.test']));
        $this->assertSame('NetworkEquipment:3', $match(['x'], [], ['fw01.example.test']));
        $this->assertSame('Computer:4', Rules::match('', ['unknown'], ['192.0.2.10'], [], $bySerial, $byName, $byIp));
        $this->assertNull(Rules::match('', ['unknown'], ['198.51.100.1'], [], $bySerial, $byName, $byIp));
        $this->assertSame('srv-web01', Rules::normalizeHost('SRV-WEB01.example.test'));
        $this->assertSame('192.0.2.10', Rules::normalizeHost('192.0.2.10'));
    }
}

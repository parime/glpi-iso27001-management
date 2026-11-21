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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Logging;

use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #853 : couverture de la journalisation, logique pure sur des hôtes et actifs fictifs.
 */
final class LogCoverageRulesTest extends TestCase
{
    public function testSettingsAreBoundedWithDefaults(): void
    {
        $this->assertSame(
            ['scope' => R::SCOPE_CRITICAL_SERVERS, 'policy_days' => 180, 'stale_days' => 7],
            R::normalizeSettings(null)
        );
        $this->assertSame(
            ['scope' => R::SCOPE_ALL, 'policy_days' => 3650, 'stale_days' => 1],
            R::normalizeSettings(['scope' => 'all', 'policy_days' => '99999', 'stale_days' => '-4'])
        );
        $this->assertSame(R::SCOPE_CRITICAL_SERVERS, R::normalizeSettings(['scope' => 'x'])['scope']);
        $this->assertSame('splunk', R::normalizePlatform(' Splunk '));
        $this->assertSame('other', R::normalizePlatform('nimporte'));
        $this->assertSame('other', R::normalizePlatform(['wazuh']));
    }

    public function testHostNamesAndMatching(): void
    {
        $this->assertSame('srv-web01', R::normalizeHost('SRV-WEB01.exemple.test'));
        $this->assertSame('192.0.2.10', R::normalizeHost('192.0.2.10'));
        $this->assertSame('', R::normalizeHost('  '));

        $names = [
            'srv-web01'          => 'Computer:1',
            'fw-01.exemple.test' => 'NetworkEquipment:4',
            'fw-01'              => 'NetworkEquipment:4',
        ];
        $ips = ['192.0.2.20' => 'Computer:2'];
        $this->assertSame('Computer:1', R::match('srv-web01.exemple.test', '', $names, $ips), 'Nom court.');
        $this->assertSame('NetworkEquipment:4', R::match('FW-01.EXEMPLE.TEST', '', $names, $ips), 'Nom complet.');
        $this->assertSame('Computer:2', R::match('inconnu', '192.0.2.20', $names, $ips), 'Adresse IP.');
        $this->assertSame('Computer:2', R::match('192.0.2.20', '', $names, $ips), 'Adresse donnée comme nom.');
        $this->assertNull(R::match('inconnu', '198.51.100.1', $names, $ips));
    }

    public function testCriticalityAndScope(): void
    {
        $this->assertTrue(R::isCritical('', 'high', '', false));
        $this->assertTrue(R::isCritical('', '', '', true), 'Publié sur Internet.');
        $this->assertFalse(R::isCritical('medium', 'medium', 'low', false));

        $this->assertTrue(R::isConcerned(R::SCOPE_CRITICAL_SERVERS, false, true, false), 'Serveur.');
        $this->assertTrue(R::isConcerned(R::SCOPE_CRITICAL_SERVERS, false, false, true), 'Matériel réseau.');
        $this->assertFalse(R::isConcerned(R::SCOPE_CRITICAL_SERVERS, false, false, false), 'Poste ordinaire.');
        $this->assertFalse(R::isConcerned(R::SCOPE_CRITICAL, false, true, true));
        $this->assertTrue(R::isConcerned(R::SCOPE_CRITICAL, true, false, false));
        $this->assertTrue(R::isConcerned(R::SCOPE_ALL, false, false, false));
    }

    public function testAssetStatus(): void
    {
        $now = '2026-03-20 12:00:00';
        $this->assertSame(R::STATUS_SILENT, R::status(false, null, $now, 7));
        $this->assertSame(R::STATUS_OK, R::status(true, null, $now, 7), 'Hôte connu sans date.');
        $this->assertSame(R::STATUS_OK, R::status(true, '2026-03-13 12:00:00', $now, 7), 'Exactement 7 jours.');
        $this->assertSame(R::STATUS_STALE, R::status(true, '2026-03-12 11:00:00', $now, 7));
        $this->assertSame(8, R::ageDays('2026-03-12 11:00:00', $now));
        $this->assertNull(R::ageDays('pas une date', $now));
        $this->assertSame(0, R::ageDays('2026-03-21 00:00:00', $now), 'Date future ramenée à 0.');
    }

    public function testRetention(): void
    {
        $this->assertSame(365, R::requiredDays(180, 365), 'Obligation plus longue que la politique.');
        $this->assertSame(180, R::requiredDays(180, 30));
        $this->assertSame(R::RETENTION_UNDECLARED, R::retentionStatus(0, 180));
        $this->assertSame(R::RETENTION_SHORT, R::retentionStatus(90, 180));
        $this->assertSame(R::RETENTION_OK, R::retentionStatus(180, 180));
    }

    public function testSummary(): void
    {
        $summary = R::summary([
            ['status' => R::STATUS_OK], ['status' => R::STATUS_OK], ['status' => R::STATUS_STALE],
            ['status' => R::STATUS_SILENT],
        ]);
        $this->assertSame(
            ['total' => 4, 'ok' => 2, 'stale' => 1, 'silent' => 1, 'rate' => 50, 'uncovered' => 2],
            $summary
        );
        $this->assertNull(R::summary([])['rate']);
    }

    public function testParseSplunkExport(): void
    {
        $csv = "host,firstTime,lastTime,recentTime,totalCount\n"
            . "srv-web01.exemple.test,1700000000,1773964800,1773964800,1200\n"
            . "SRV-WEB01.exemple.test,1700000000,1773878400,1773878400,15\n"
            . ",,,,\n"
            . "fw-01,1700000000,,,3\n";
        $parsed = R::parseCsv($csv);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows'], 'Lignes du même hôte fusionnées, ligne vide ignorée.');
        $this->assertSame('srv-web01.exemple.test', $parsed['rows'][0]['host']);
        $this->assertSame(
            date('Y-m-d H:i:s', 1773964800),
            $parsed['rows'][0]['last_event'],
            'Dernier événement le plus récent.'
        );
        $this->assertNull($parsed['rows'][1]['last_event'], 'Date absente.');
    }

    public function testParseSemicolonExportWithIpAndBadAddress(): void
    {
        $csv = "\xEF\xBB\xBFsource;IP address;Last seen\n"
            . "pare-feu;192.0.2.1;2026-03-01 08:00:00\n"
            . "poste;pas-une-ip;2026-03-02 08:00:00\n";
        $parsed = R::parseCsv($csv, date_default_timezone_get());
        $this->assertSame([], $parsed['errors']);
        $this->assertSame('pare-feu', $parsed['rows'][0]['host']);
        $this->assertSame('192.0.2.1', $parsed['rows'][0]['ip']);
        $this->assertSame('', $parsed['rows'][1]['ip'], 'Adresse invalide écartée.');
        $this->assertSame('2026-03-01 08:00:00', $parsed['rows'][0]['last_event']);
    }

    public function testParseWithoutHostColumn(): void
    {
        $this->assertSame(['missing_host'], R::parseCsv("count,index\n3,main\n")['errors']);
        $this->assertSame(['missing_host'], R::parseCsv('')['errors']);
    }

    public function testLatest(): void
    {
        $this->assertSame('2026-01-02 00:00:00', R::latest('2026-01-01 00:00:00', '2026-01-02 00:00:00'));
        $this->assertSame('2026-01-01 00:00:00', R::latest('2026-01-01 00:00:00', null));
        $this->assertNull(R::latest(null, ''));
    }
}

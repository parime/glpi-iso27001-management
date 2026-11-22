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

use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #842 : règles pures des services exposés lus dans Uptime Kuma (aucune base, aucun réseau).
 */
final class UptimeKumaRulesTest extends TestCase
{
    public function testBaseUrlIsBuiltFromAnyPageOfTheInterface(): void
    {
        $this->assertSame('https://status.example.test', Rules::normalizeBaseUrl('https://Status.Example.test/'));
        $this->assertSame(
            'https://status.example.test',
            Rules::normalizeBaseUrl('https://status.example.test/dashboard/12')
        );
        $this->assertSame(
            'https://status.example.test/kuma',
            Rules::normalizeBaseUrl('https://status.example.test/kuma/metrics')
        );
        $this->assertSame(
            'https://status.example.test:3001',
            Rules::normalizeBaseUrl('https://status.example.test:3001/status/customers')
        );
        $this->assertNull(Rules::normalizeBaseUrl('http://status.example.test'));
        $this->assertSame('http://192.0.2.10:3001', Rules::normalizeBaseUrl('http://192.0.2.10:3001', true));
        $this->assertNull(Rules::normalizeBaseUrl('https://user:secret@status.example.test'));
        $this->assertNull(Rules::normalizeBaseUrl('https://status.example.test/?token=1'));
        $this->assertNull(Rules::normalizeBaseUrl('ftp://status.example.test'));
        $this->assertNull(Rules::normalizeBaseUrl('https://status.example.test/../etc'));
    }

    public function testSlugsAndSettingsAreBounded(): void
    {
        $this->assertSame(
            ['customers', 'intranet-2'],
            Rules::normalizeSlugs(" Customers, intranet-2\ncustomers ; ../x")
        );
        $this->assertSame([], Rules::normalizeSlugs(''));
        $this->assertSame(
            ['period_days' => 30, 'cert_days' => 30, 'outage_minutes' => 30],
            Rules::normalizeSettings(null)
        );
        $this->assertSame(
            ['period_days' => 90, 'cert_days' => 1, 'outage_minutes' => 1440],
            Rules::normalizeSettings(['period_days' => '400', 'cert_days' => '-3', 'outage_minutes' => 99999])
        );
    }

    public function testPrometheusMetricsAreParsed(): void
    {
        $body = implode("\n", [
            '# HELP monitor_status Monitor Status (1 = UP, 0= DOWN, 2= PENDING, 3= MAINTENANCE)',
            '# TYPE monitor_status gauge',
            'monitor_status{monitor_name="Portal \"web\"",monitor_type="http",'
                . 'monitor_url="https://portal.example.test/",monitor_hostname="null",monitor_port="null"} 1',
            'monitor_cert_days_remaining{monitor_name="Portal",monitor_type="http"} 12',
            'process_cpu_seconds_total 3.2',
            'monitor_status{monitor_name="Bad"} not-a-number',
        ]);
        $rows = Rules::parseMetrics($body);
        $this->assertCount(2, $rows);
        $this->assertSame('monitor_status', $rows[0]['metric']);
        $this->assertSame('Portal "web"', $rows[0]['labels']['monitor_name']);
        $this->assertSame('', Rules::labelValue($rows[0]['labels']['monitor_hostname']));
        $this->assertSame(12.0, $rows[1]['value']);
    }

    public function testMonitorKeyHostAndTime(): void
    {
        $this->assertSame('id:42', Rules::monitorKey('42', 'Portal'));
        $this->assertSame(Rules::monitorKey('', 'Portal'), Rules::monitorKey('', ' portal '));
        $this->assertStringStartsWith('name:', Rules::monitorKey('', 'Portal'));
        $this->assertSame('portal.example.test', Rules::hostOf('https://Portal.example.test:8443/login', ''));
        $this->assertSame('192.0.2.5', Rules::hostOf('', '192.0.2.5'));
        $this->assertSame('', Rules::hostOf('', ''));
        $this->assertSame(gmmktime(10, 0, 0, 1, 15, 2026), Rules::parseTime('2026-01-15 10:00:00.000'));
        $this->assertSame(gmmktime(9, 0, 0, 1, 15, 2026), Rules::parseTime('2026-01-15T10:00:00+01:00'));
        $this->assertNull(Rules::parseTime('yesterday'));
    }

    public function testAvailabilityAndOutagesAreComputedFromTheReadings(): void
    {
        $now = 1_000_000;
        $from = $now - 10 * 3600;
        // En service, panne de deux heures (dont une « en attente »), en service, maintenance, en
        // service, puis panne en cours depuis une heure.
        $samples = [
            [$now - 10 * 3600, Rules::STATUS_UP],
            [$now - 8 * 3600, Rules::STATUS_DOWN],
            [$now - 7 * 3600, Rules::STATUS_PENDING],
            [$now - 6 * 3600, Rules::STATUS_UP],
            [$now - 3 * 3600, Rules::STATUS_MAINTENANCE],
            [$now - 2 * 3600, Rules::STATUS_UP],
            [$now - 3600, Rules::STATUS_DOWN],
        ];
        $result = Rules::analyse($samples, $from, $now);
        // En service : 2 h + 2 h (relevé suivant à 3 h, limité à 2 h) + 1 h de maintenance + 1 h.
        $this->assertSame(6 * 3600, $result['up']);
        // En panne : 1 h (l'heure « en attente » n'est pas comptée) + 1 h en cours.
        $this->assertSame(2 * 3600, $result['down']);
        $this->assertSame(75.0, $result['availability']);
        $this->assertSame(2 * 3600, $result['longest']);
        $this->assertSame($now - 3600, $result['down_since']);

        // Un trou de plus de deux heures entre deux relevés n'est pas compté.
        $gap = Rules::analyse([[$now - 6 * 3600, Rules::STATUS_UP], [$now - 600, Rules::STATUS_UP]], $from, $now);
        $this->assertSame(7200 + 600, $gap['up']);
        $this->assertSame(100.0, $gap['availability']);
        $this->assertNull($gap['down_since']);
        $this->assertNull(Rules::analyse([], $from, $now)['availability']);
    }

    public function testCertificatesRtoAndIncidentSeverity(): void
    {
        $this->assertSame(Rules::CERT_NONE, Rules::certStatus(null, null, 30));
        $this->assertSame(Rules::CERT_EXPIRED, Rules::certStatus(-2, true, 30));
        $this->assertSame(Rules::CERT_INVALID, Rules::certStatus(80, false, 30));
        $this->assertSame(Rules::CERT_EXPIRING, Rules::certStatus(30, true, 30));
        $this->assertSame(Rules::CERT_OK, Rules::certStatus(31, true, 30));
        $this->assertTrue(Rules::certNeedsAction(Rules::CERT_EXPIRING));
        $this->assertFalse(Rules::certNeedsAction(Rules::CERT_OK));

        $this->assertTrue(Rules::exceedsRto(7201, 2));
        $this->assertFalse(Rules::exceedsRto(7200, 2));
        $this->assertFalse(Rules::exceedsRto(99999, 0));
        $this->assertTrue(Rules::isLongOutage(1800, 30));
        $this->assertFalse(Rules::isLongOutage(1799, 30));
        $this->assertSame('critical', Rules::incidentSeverity(true, false));
        $this->assertSame('major', Rules::incidentSeverity(false, true));
        $this->assertSame('minor', Rules::incidentSeverity(false, false));
        $this->assertSame(Rules::STATUS_UNKNOWN, Rules::normalizeStatus('7'));
        $this->assertSame(Rules::STATUS_DOWN, Rules::normalizeStatus('0'));
    }
}

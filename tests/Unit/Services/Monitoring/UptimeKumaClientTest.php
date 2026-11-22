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

use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #842 : client Uptime Kuma en lecture seule, sur un faux serveur (aucun appel réseau).
 */
final class UptimeKumaClientTest extends TestCase
{
    private const BASE = 'https://status.example.test';
    private const KEY = 'uk1_cle-fictive';

    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    private array $calls = [];

    /**
     * @param array<string, array{0: int, 1: string}> $answers chemin => [statut, corps]
     */
    private function client(array $answers, string $key = self::KEY): UptimeKumaClient
    {
        $this->calls = [];

        return new UptimeKumaClient(
            self::BASE,
            $key,
            function (string $method, string $url, array $headers, ?string $body) use ($answers): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
                $path = substr($url, strlen(self::BASE));
                [$status, $answer] = $answers[$path] ?? [404, ''];

                return ['status' => $status, 'body' => $answer, 'retry_after' => 0];
            }
        );
    }

    private static function metrics(): string
    {
        return implode("\n", [
            '# TYPE monitor_status gauge',
            'monitor_status{monitor_name="Portal",monitor_type="http",monitor_url="https://portal.example.test/'
                . '?token=secret",monitor_hostname="null",monitor_port="null"} 0',
            'monitor_response_time{monitor_name="Portal",monitor_type="http",monitor_url="https://portal.example.test/'
                . '?token=secret",monitor_hostname="null",monitor_port="null"} 250',
            'monitor_cert_days_remaining{monitor_name="Portal",monitor_type="http"} 9',
            'monitor_cert_is_valid{monitor_name="Portal",monitor_type="http"} 1',
            'monitor_status{monitor_name="Mail relay",monitor_type="port",monitor_url="null",'
                . 'monitor_hostname="mail.example.test",monitor_port="25"} 1',
        ]);
    }

    public function testOnlyReadPathsAreCalled(): void
    {
        $this->assertTrue(UptimeKumaClient::isReadPath('/metrics'));
        $this->assertTrue(UptimeKumaClient::isReadPath('/api/status-page/customers'));
        $this->assertTrue(UptimeKumaClient::isReadPath('/api/status-page/heartbeat/customers'));
        $this->assertFalse(UptimeKumaClient::isReadPath('/api/push/abc'));
        $this->assertFalse(UptimeKumaClient::isReadPath('/api/status-page/../../metrics'));
        $client = $this->client([]);
        $this->assertSame(UptimeKumaClient::ERROR_REFUSED, $client->get('/api/push/abc')['error']);
        $this->assertSame([], $this->calls, 'Aucun appel pour un chemin refusé.');
    }

    public function testMetricsAreReadWithTheKeyInBasicAuthentication(): void
    {
        $client = $this->client(['/metrics' => [200, self::metrics()]]);
        $result = $client->fetch([], 1_000_000);
        $this->assertTrue($result['complete']);
        $this->assertSame('', $result['error']);
        $this->assertCount(1, $this->calls);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('Basic ' . base64_encode(':' . self::KEY), $this->calls[0]['headers']['Authorization']);
        $this->assertStringNotContainsString(self::KEY, $this->calls[0]['url']);

        $byName = array_column($result['monitors'], null, 'name');
        $portal = $byName['Portal'];
        $this->assertSame(Rules::STATUS_DOWN, $portal['status']);
        $this->assertSame(250, $portal['response_time']);
        $this->assertSame(9, $portal['cert_days']);
        $this->assertTrue($portal['cert_valid']);
        $this->assertSame('https://portal.example.test/', $portal['url'], 'Paramètres de l\'adresse retirés.');
        $this->assertSame('mail.example.test', $byName['Mail relay']['hostname']);
        $this->assertSame('25', $byName['Mail relay']['port']);
        // Un relevé par sonde à l'heure de la lecture.
        $this->assertCount(2, $result['samples']);
        $this->assertSame(1_000_000, $result['samples'][0]['time']);
    }

    public function testStatusPagesAddHistoryAndMergeWithMetrics(): void
    {
        $page = (string) json_encode(['publicGroupList' => [[
            'name' => 'Services', 'monitorList' => [
                ['id' => 7, 'name' => 'portal', 'type' => 'http', 'certExpiryDaysRemaining' => 9, 'validCert' => true],
                ['id' => 8, 'name' => 'Intranet', 'type' => 'http', 'url' => 'https://intranet.example.test'],
            ],
        ]]]);
        $beats = (string) json_encode([
            'heartbeatList' => [
                '7' => [
                    ['status' => 1, 'time' => '2026-01-15 09:00:00.000', 'ping' => 100],
                    ['status' => 0, 'time' => '2026-01-15 10:00:00.000', 'ping' => null],
                ],
                '8' => [['status' => 1, 'time' => '2026-01-15 10:00:00', 'ping' => 40]],
            ],
            'uptimeList' => ['7_24' => 0.95, '8_24' => 1],
        ]);
        $client = $this->client([
            '/metrics' => [200, self::metrics()],
            '/api/status-page/customers' => [200, $page],
            '/api/status-page/heartbeat/customers' => [200, $beats],
        ]);
        $result = $client->fetch(['customers'], gmmktime(11, 0, 0, 1, 15, 2026));
        $this->assertTrue($result['complete']);
        $this->assertSame(['customers'], $result['pages']);
        $byName = array_column($result['monitors'], null, 'name');
        $this->assertCount(3, $byName, 'Portal de /metrics et portal de la page : une seule sonde.');
        $portal = $byName['Portal'];
        $this->assertSame('id:7', $portal['key']);
        $this->assertSame('7', $portal['monitor_id']);
        $this->assertSame(95.0, $portal['uptime_24h']);
        $this->assertSame(['customers'], $portal['pages']);
        $this->assertSame('https://intranet.example.test', $byName['Intranet']['url']);
        // Relevés de /metrics repris sous la clé de la page de statut.
        $keys = array_count_values(array_column($result['samples'], 'key'));
        $this->assertSame(3, $keys['id:7']);
        $this->assertSame(1, $keys['id:8']);
    }

    public function testErrorsAreReportedAndNothingIsComplete(): void
    {
        $this->assertSame(UptimeKumaClient::ERROR_CONFIG, $this->client([], '')->fetch([], 1)['error']);
        $refused = $this->client(['/metrics' => [401, '']])->fetch([], 1);
        $this->assertSame(UptimeKumaClient::ERROR_UNAUTHORIZED, $refused['error']);
        $this->assertFalse($refused['complete']);
        $html = $this->client(['/metrics' => [200, '<html>login</html>']])->fetch([], 1);
        $this->assertSame(UptimeKumaClient::ERROR_INVALID, $html['error']);
        $missing = $this->client([], '')->fetch(['unknown'], 1);
        $this->assertSame(UptimeKumaClient::ERROR_PAGE, $missing['error']);
        $this->assertSame('unknown', $missing['page']);
        $unreachable = new UptimeKumaClient(
            self::BASE,
            '',
            static fn (): array => ['status' => 0, 'body' => '', 'retry_after' => 0, 'error' => 'transport_error: x']
        );
        $this->assertSame(UptimeKumaClient::ERROR_UNREACHABLE, $unreachable->fetch(['customers'], 1)['error']);
    }
}

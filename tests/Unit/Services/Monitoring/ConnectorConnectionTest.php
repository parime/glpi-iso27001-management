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

use GlpiPlugin\Grcmanager\Services\Backup\VeeamClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixClient;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1042 : bouton « Tester la connexion » des connecteurs de supervision, sur de faux serveurs
 * en mémoire (aucun appel réseau). Assertions sur des codes, jamais sur des textes traduits.
 */
final class ConnectorConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function zabbix(int $hostStatus = 200): ZabbixClient
    {
        return new ZabbixClient(
            'https://zabbix.example.test/api_jsonrpc.php',
            'jeton-fictif-zabbix',
            function (string $method, string $url, array $headers, ?string $body) use ($hostStatus): array {
                $request = (array) json_decode((string) $body, true);
                $rpc = (string) ($request['method'] ?? '');
                $this->calls[] = $rpc;
                if ($rpc === 'host.get' && $hostStatus !== 200) {
                    return ['status' => $hostStatus, 'body' => '', 'retry_after' => 0];
                }
                $result = $rpc === 'apiinfo.version' ? '7.0.0' : '3';

                return [
                    'status'      => 200,
                    'body'        => (string) json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $request['id'] ?? 1]),
                    'retry_after' => 0,
                ];
            }
        );
    }

    public function testZabbixTestReadsTheVersionThenCountsTheHosts(): void
    {
        $result = $this->zabbix()->test();

        $this->assertSame(['version' => '7.0.0', 'error' => ''], $result);
        $this->assertSame(['apiinfo.version', 'host.get'], $this->calls);
    }

    public function testZabbixTestReportsARefusedToken(): void
    {
        $this->assertNotSame('', $this->zabbix(403)->test()['error']);
    }

    public function testZabbixTestWithoutTokenIsNotConfigured(): void
    {
        $client = new ZabbixClient('https://zabbix.example.test/api_jsonrpc.php', '', static fn (): array => [
            'status' => 200,
            'body' => (string) json_encode(['jsonrpc' => '2.0', 'result' => '7.0.0', 'id' => 1]),
            'retry_after' => 0,
        ]);

        $this->assertSame(ZabbixClient::ERROR_CONFIG, $client->test()['error']);
    }

    /**
     * @param array<string, int> $statuses chemin => code HTTP
     */
    private function kuma(string $key, array $statuses): UptimeKumaClient
    {
        return new UptimeKumaClient(
            'https://status.example.test',
            $key,
            function (string $method, string $url) use ($statuses): array {
                $path = substr($url, strlen('https://status.example.test'));
                $this->calls[] = $method . ' ' . $path;

                return ['status' => $statuses[$path] ?? 404, 'body' => '{}', 'retry_after' => 0];
            }
        );
    }

    public function testUptimeKumaTestCallsTheMetricsThenEachStatusPage(): void
    {
        $client = $this->kuma('cle-fictive', ['/metrics' => 200, '/api/status-page/customers' => 200]);

        $this->assertSame(['error' => '', 'page' => ''], $client->test(['customers']));
        $this->assertSame(['GET /metrics', 'GET /api/status-page/customers'], $this->calls);
    }

    public function testUptimeKumaTestNamesTheMissingStatusPage(): void
    {
        $result = $this->kuma('', [])->test(['intranet']);

        $this->assertSame(['error' => UptimeKumaClient::ERROR_PAGE, 'page' => 'intranet'], $result);
    }

    public function testUptimeKumaTestReportsARefusedKeyAndAMissingConfiguration(): void
    {
        $this->assertSame(UptimeKumaClient::ERROR_UNAUTHORIZED, $this->kuma('cle-fictive', ['/metrics' => 401])->test([])['error']);
        $this->assertSame(UptimeKumaClient::ERROR_CONFIG, $this->kuma('', [])->test([])['error']);
        $this->assertSame([], $this->calls);
    }

    private function veeam(int $tokenStatus, string $password = 'mot-de-passe-fictif'): VeeamClient
    {
        return new VeeamClient(
            'https://veeam.example.test:9419',
            'EXAMPLE\\lecteur',
            $password,
            '1.2-rev0',
            function (string $method, string $url) use ($tokenStatus): array {
                $this->calls[] = $method . ' ' . parse_url($url, PHP_URL_PATH);

                return [
                    'status'      => $tokenStatus,
                    'body'        => $tokenStatus === 200 ? (string) json_encode(['access_token' => 'jeton-fictif']) : '',
                    'retry_after' => 0,
                ];
            }
        );
    }

    public function testVeeamTestOnlyAsksForAnAccessToken(): void
    {
        $this->assertSame('', $this->veeam(200)->test());
        $this->assertCount(1, $this->calls);
        $this->assertStringStartsWith('POST ', $this->calls[0]);
    }

    public function testVeeamTestReportsRefusedCredentialsAndAMissingPassword(): void
    {
        $this->assertSame(VeeamClient::ERROR_UNAUTHORIZED, $this->veeam(401)->test());
        $this->calls = [];
        $this->assertSame(VeeamClient::ERROR_CONFIG, $this->veeam(200, '')->test());
        $this->assertSame([], $this->calls);
    }
}

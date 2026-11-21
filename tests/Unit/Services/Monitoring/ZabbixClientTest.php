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

use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixClient;
use PHPUnit\Framework\TestCase;

/**
 * Issue #836 : lecture d'un faux serveur Zabbix (en mémoire, aucun appel réseau). Assertions sur des
 * codes et des nombres, jamais sur des textes traduits.
 */
final class ZabbixClientTest extends TestCase
{
    public const URL = 'https://zabbix.example.test/api_jsonrpc.php';
    private const TOKEN = 'jeton-fictif-zabbix';
    private const NOW = 1_800_000_000;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    private array $calls = [];

    /**
     * Faux serveur : une réponse (résultat ou erreur JSON-RPC) par méthode.
     *
     * @param array<string, mixed> $answers méthode => résultat, ou ['__error' => [...]], ou ['__status' => int]
     */
    private function client(array $answers, string $version = '7.0.0', string $token = self::TOKEN): ZabbixClient
    {
        $answers += ['apiinfo.version' => $version];

        return new ZabbixClient(
            self::URL,
            $token,
            function (string $method, string $url, array $headers, ?string $body) use ($answers): array {
                $request = json_decode((string) $body, true);
                $this->assertIsArray($request);
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $request];
                $rpc = (string) $request['method'];
                $answer = $answers[$rpc] ?? [];
                if (is_array($answer) && isset($answer['__status'])) {
                    return ['status' => (int) $answer['__status'], 'body' => '', 'retry_after' => 0];
                }
                if ($rpc === 'event.get' && isset($answers['event.get#ids']) && isset($request['params']['eventids'])) {
                    $answer = $answers['event.get#ids'];
                }
                $payload = is_array($answer) && isset($answer['__error'])
                    ? ['jsonrpc' => '2.0', 'error' => $answer['__error'], 'id' => $request['id']]
                    : ['jsonrpc' => '2.0', 'result' => $answer, 'id' => $request['id']];

                return ['status' => 200, 'body' => (string) json_encode($payload), 'retry_after' => 0];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function server(): array
    {
        return [
            'host.get'      => [
                [
                    'hostid' => '10501', 'host' => 'srv-web01', 'name' => 'Web server', 'status' => '0',
                    'interfaces' => [['ip' => '192.0.2.10', 'dns' => 'srv-web01.example.test', 'available' => '1']],
                    'inventory' => ['serialno_a' => 'SN-1234'],
                ],
                [
                    'hostid' => '10502', 'host' => 'fw01', 'name' => 'fw01', 'status' => '1',
                    'interfaces' => [], 'inventory' => [],
                ],
                ['hostid' => 'x', 'host' => 'bad'],
            ],
            'event.get'     => [
                [
                    'eventid' => '9001', 'name' => 'Disk space is critically low', 'severity' => '4',
                    'clock' => (string) (self::NOW - 7200), 'r_eventid' => '9002', 'acknowledged' => '1',
                    'hosts' => [['hostid' => '10501']], 'tags' => [['tag' => 'component', 'value' => 'storage']],
                ],
                [
                    'eventid' => '9003', 'name' => 'Unavailable by ICMP ping', 'severity' => '5',
                    'clock' => (string) (self::NOW - 600), 'r_eventid' => '0', 'acknowledged' => '0',
                    'hosts' => [['hostid' => '10501'], ['hostid' => '10502']], 'tags' => [],
                ],
            ],
            'event.get#ids' => [['eventid' => '9002', 'clock' => (string) (self::NOW - 3600)]],
            'problem.get'   => [['eventid' => '9003']],
            'sla.get'       => [
                ['slaid' => '1', 'name' => 'Customer portal', 'period' => '2', 'slo' => '99.9', 'status' => '1'],
            ],
            'sla.getsli'    => [
                'periods'    => [
                    ['period_from' => self::NOW - 60 * 86400, 'period_to' => self::NOW - 30 * 86400],
                    ['period_from' => self::NOW - 30 * 86400, 'period_to' => self::NOW],
                ],
                'serviceids' => [7],
                'sli'        => [
                    [['uptime' => 100, 'downtime' => 0, 'sli' => 100, 'error_budget' => 0]],
                    [['uptime' => 100, 'downtime' => 5400, 'sli' => 99.7917, 'error_budget' => 0]],
                ],
            ],
            'service.get'   => [['serviceid' => '7', 'name' => 'Portal']],
        ];
    }

    public function testReadsEverythingWithReadMethodsOnlyAndTheTokenInTheHeader(): void
    {
        $result = $this->client(self::server())->fetch(30, self::NOW);

        $this->assertSame('', $result['error']);
        $this->assertTrue($result['complete']);
        $this->assertTrue($result['sla_supported']);
        $this->assertSame('7.0.0', $result['version']);
        $this->assertCount(3, $result['hosts']);
        $this->assertCount(2, $result['events']);
        $this->assertSame(['9003'], $result['open']);
        $this->assertSame(['9002' => self::NOW - 3600], $result['recoveries']);
        foreach ($this->calls as $call) {
            // Une seule adresse, en POST, et seulement des méthodes de lecture.
            $this->assertSame(self::URL, $call['url']);
            $this->assertSame('POST', $call['method']);
            $this->assertTrue(ZabbixClient::isReadMethod((string) $call['body']['method']));
            $this->assertMatchesRegularExpression(
                '/(\.get|\.getsli|apiinfo\.version)$/',
                (string) $call['body']['method']
            );
            // Zabbix 7 : jeton dans l'en-tête, jamais dans le corps.
            $this->assertArrayNotHasKey('auth', $call['body']);
            if ($call['body']['method'] === 'apiinfo.version') {
                $this->assertArrayNotHasKey('Authorization', $call['headers']);
            } else {
                $this->assertSame('Bearer ' . self::TOKEN, $call['headers']['Authorization']);
            }
            $this->assertStringNotContainsString(self::TOKEN, $call['url']);
        }
    }

    public function testOlderZabbixGetsTheTokenInTheBody(): void
    {
        $this->client(self::server(), '6.0.25')->fetch(30, self::NOW);

        $authenticated = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['body']['method'] !== 'apiinfo.version'
        ));
        $this->assertNotEmpty($authenticated);
        foreach ($authenticated as $call) {
            $this->assertSame(self::TOKEN, $call['body']['auth']);
            $this->assertArrayNotHasKey('Authorization', $call['headers']);
        }
    }

    public function testWriteMethodsAreRefusedBeforeSending(): void
    {
        $client = $this->client(self::server());
        $writes = [
            'host.update', 'host.create', 'problem.acknowledge', 'event.acknowledge', 'user.login', 'host.delete',
        ];
        foreach ($writes as $method) {
            $this->assertSame(ZabbixClient::ERROR_METHOD, $client->call($method, [])['error']);
        }
        $this->assertSame([], $this->calls);
    }

    public function testTooOldVersionAndRefusedTokenStopTheReading(): void
    {
        $tooOld = $this->client(self::server(), '5.0.30')->fetch(30, self::NOW);
        $this->assertSame(ZabbixClient::ERROR_VERSION, $tooOld['error']);

        $refused = self::server();
        $refused['host.get'] = [
            '__error' => ['code' => -32602, 'message' => 'Invalid params.', 'data' => 'Not authorized.'],
        ];
        $result = $this->client($refused)->fetch(30, self::NOW);
        $this->assertSame(ZabbixClient::ERROR_UNAUTHORIZED, $result['error']);
        $this->assertFalse($result['complete']);

        $down = self::server();
        $down['problem.get'] = ['__status' => 0];
        $this->assertSame(ZabbixClient::ERROR_UNREACHABLE, $this->client($down)->fetch(30, self::NOW)['error']);

        $noToken = $this->client(self::server(), '7.0.0', '')->fetch(30, self::NOW);
        $this->assertSame(ZabbixClient::ERROR_CONFIG, $noToken['error']);
    }

    public function testSlaAbsentFromAnOldZabbixIsNotAnError(): void
    {
        $old = self::server();
        $old['sla.get'] = [
            '__error' => ['code' => -32601, 'message' => 'Method not found.', 'data' => 'Incorrect method "sla.get".'],
        ];
        $result = $this->client($old, '5.4.12')->fetch(30, self::NOW);

        $this->assertSame('', $result['error']);
        $this->assertTrue($result['complete']);
        $this->assertFalse($result['sla_supported']);
        $this->assertSame([], $result['slas']);
    }

    public function testRowsAreNormalized(): void
    {
        $result = $this->client(self::server())->fetch(30, self::NOW);

        $hosts = ZabbixClient::hostRows($result['hosts']);
        $this->assertCount(2, $hosts);
        $this->assertTrue($hosts[0]['monitored']);
        $this->assertSame(['192.0.2.10'], $hosts[0]['ips']);
        $this->assertSame(['srv-web01.example.test'], $hosts[0]['dns']);
        $this->assertSame('SN-1234', $hosts[0]['serial']);
        $this->assertFalse($hosts[1]['monitored']);

        $problems = ZabbixClient::problemRows($result['events'], $result['open'], $result['recoveries']);
        // 9001 sur un hôte, 9003 sur deux hôtes.
        $this->assertCount(3, $problems);
        $this->assertSame('storage', $problems[0]['capacity']);
        $this->assertFalse($problems[0]['is_open']);
        $this->assertSame(self::NOW - 3600, $problems[0]['r_clock']);
        $this->assertTrue($problems[0]['acknowledged']);
        $this->assertTrue($problems[1]['is_open']);
        $this->assertNull($problems[1]['r_clock']);
        $this->assertSame(5, $problems[1]['severity']);

        $services = ZabbixClient::serviceRows($result['slas']);
        $this->assertCount(1, $services);
        $this->assertSame('Portal', $services[0]['service_name']);
        $this->assertSame(99.9, $services[0]['slo']);
        $this->assertSame(99.7917, $services[0]['sli']);
        $this->assertSame(100.0, $services[0]['sli_previous']);
        $this->assertSame(5400, $services[0]['downtime']);
    }

    public function testTheTokenNeverAppearsInAnError(): void
    {
        $refused = self::server();
        $refused['apiinfo.version'] = ['__status' => 401];
        $result = $this->client($refused)->fetch(30, self::NOW);

        $this->assertSame(ZabbixClient::ERROR_UNAUTHORIZED, $result['error']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
    }
}

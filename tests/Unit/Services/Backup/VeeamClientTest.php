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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Backup;

use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules as Rules;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamClient;
use PHPUnit\Framework\TestCase;

/**
 * Issue #838 : lecture de Veeam Backup & Replication par un faux serveur REST (en mémoire, aucun
 * appel réseau). Assertions sur des codes et des nombres, jamais sur des textes traduits.
 */
final class VeeamClientTest extends TestCase
{
    private const BASE = 'https://veeam.exemple.test:9419';
    private const USER = 'EXEMPLE\\lecteur-glpi';
    private const PASSWORD = 'mot-de-passe-fictif';
    private const TOKEN = 'jeton-fictif';
    private const NOW = '2026-06-15 12:00:00';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /**
     * @param array<string, list<array{status: int, body: string, error?: string}>> $pages réponses par chemin
     */
    private function client(array $pages, int $tokenStatus = 200, string $tokenError = ''): VeeamClient
    {
        return new VeeamClient(
            self::BASE,
            self::USER,
            self::PASSWORD,
            '1.2-rev0',
            function (
                string $method,
                string $url,
                array $headers,
                ?string $body
            ) use (
                &$pages,
                $tokenStatus,
                $tokenError
            ): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                if ($method === 'POST') {
                    return [
                        'status'      => $tokenStatus,
                        'body'        => $tokenStatus === 200 ? '{"access_token":"' . self::TOKEN . '"}' : '{}',
                        'retry_after' => 0,
                        'error'       => $tokenError,
                    ];
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                $page = isset($pages[$path]) ? array_shift($pages[$path]) : null;

                return ($page ?? ['status' => 500, 'body' => '']) + ['retry_after' => 0];
            }
        );
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return array{status: int, body: string}
     */
    private static function page(array $data, ?int $total = null): array
    {
        return ['status' => 200, 'body' => (string) json_encode([
            'data'       => $data,
            'pagination' => ['total' => $total ?? count($data), 'count' => count($data), 'skip' => 0, 'limit' => 500],
        ])];
    }

    public function testReadsRestorePointsAndJobsWithGetOnly(): void
    {
        $result = $this->client([
            VeeamClient::RESTORE_POINTS_PATH => [self::page([
                ['name' => 'srv-paie', 'creationTime' => '2026-06-15T01:00:00Z', 'platformName' => 'VMware'],
                ['name' => 'srv-paie', 'creationTime' => '2026-06-14T01:00:00Z', 'platformName' => 'VMware'],
                ['name' => 'SRV-WEB', 'creationTime' => '2026-06-10T01:00:00Z'],
                ['name' => '', 'creationTime' => '2026-06-10T01:00:00Z'],
                ['name' => 'sans-date'],
            ])],
            VeeamClient::JOB_STATES_PATH     => [self::page([
                [
                    'name' => 'Serveurs', 'type' => 'Backup', 'lastRun' => '2026-06-15T01:00:00Z',
                    'lastResult' => 'Success', 'objectsCount' => 12,
                ],
                ['name' => 'Postes', 'type' => 'EndpointBackup', 'lastResult' => 'Failed', 'objectsCount' => 'x'],
                ['type' => 'Backup'],
            ])],
        ])->fetch(self::NOW);

        self::assertSame('', $result['error']);
        self::assertTrue($result['complete']);
        self::assertSame(5, $result['points']);
        self::assertCount(2, $result['hosts']);
        $hosts = array_column($result['hosts'], null, 'host');
        self::assertSame(Rules::RESULT_SUCCESS, $hosts['srv-paie']['last_result']);
        self::assertNotNull($hosts['srv-paie']['last_success']);
        self::assertSame(
            strtotime('2026-06-15T01:00:00Z'),
            strtotime((string) $hosts['srv-paie']['last_success'])
        );
        self::assertCount(2, $result['jobs']);
        self::assertSame(Rules::RESULT_FAILED, $result['jobs'][1]['last_result']);
        self::assertSame(12, $result['jobs'][0]['objects']);
        self::assertSame(0, $result['jobs'][1]['objects']);

        // Une seule requête POST (le jeton), sur le serveur configuré ; tout le reste en GET.
        $posts = array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'POST'));
        self::assertCount(1, $posts);
        self::assertSame(self::BASE . VeeamClient::TOKEN_PATH, $posts[0]['url']);
        self::assertStringContainsString('grant_type=password', (string) $posts[0]['body']);
        foreach ($this->calls as $call) {
            self::assertStringStartsWith(self::BASE . '/api/', $call['url']);
            self::assertStringNotContainsString(self::PASSWORD, $call['url']);
            self::assertSame('1.2-rev0', $call['headers']['x-api-version']);
            if ($call['method'] === 'GET') {
                self::assertSame('Bearer ' . self::TOKEN, $call['headers']['Authorization']);
                self::assertNull($call['body']);
            }
        }
        self::assertStringContainsString('createdAfterFilter=2026-05-15', $this->calls[1]['url']);
    }

    public function testFollowsPagesUntilTheTotal(): void
    {
        $first = [];
        for ($i = 0; $i < VeeamClient::PAGE_SIZE; $i++) {
            $first[] = ['name' => 'vm-' . $i, 'creationTime' => '2026-06-15T01:00:00Z'];
        }
        $result = $this->client([
            VeeamClient::RESTORE_POINTS_PATH => [
                self::page($first, VeeamClient::PAGE_SIZE + 1),
                self::page(
                    [['name' => 'vm-last', 'creationTime' => '2026-06-15T01:00:00Z']],
                    VeeamClient::PAGE_SIZE + 1
                ),
            ],
            VeeamClient::JOB_STATES_PATH     => [self::page([])],
        ])->fetch(self::NOW);

        self::assertSame('', $result['error']);
        self::assertSame(VeeamClient::PAGE_SIZE + 1, $result['points']);
        self::assertStringContainsString('skip=' . VeeamClient::PAGE_SIZE, $this->calls[2]['url']);
    }

    public function testTooManyPagesReplaceNothing(): void
    {
        $full = [];
        for ($i = 0; $i < VeeamClient::PAGE_SIZE; $i++) {
            $full[] = ['name' => 'vm-' . $i, 'creationTime' => '2026-06-15T01:00:00Z'];
        }
        $result = $this->client([
            VeeamClient::RESTORE_POINTS_PATH => [self::page($full, 99999), self::page($full, 99999)],
        ])->fetch(self::NOW, 2);

        self::assertSame(VeeamClient::ERROR_TOO_LARGE, $result['error']);
        self::assertFalse($result['complete']);
        self::assertSame([], $result['hosts']);
    }

    public function testErrorsAreCodesWithoutSecrets(): void
    {
        $refused = $this->client([], 401)->fetch(self::NOW);
        self::assertSame(VeeamClient::ERROR_UNAUTHORIZED, $refused['error']);
        self::assertSame([], $refused['hosts']);

        $private = $this->client([], 0, 'internal_address')->fetch(self::NOW);
        self::assertSame(VeeamClient::ERROR_PRIVATE, $private['error']);

        $down = $this->client([], 0, 'transport_error: timeout')->fetch(self::NOW);
        self::assertSame(VeeamClient::ERROR_UNREACHABLE, $down['error']);

        $forbidden = $this->client([VeeamClient::RESTORE_POINTS_PATH => [['status' => 403, 'body' => '']]])
            ->fetch(self::NOW);
        self::assertSame(VeeamClient::ERROR_FORBIDDEN, $forbidden['error']);

        $invalid = $this->client([VeeamClient::RESTORE_POINTS_PATH => [['status' => 200, 'body' => '<html>']]])
            ->fetch(self::NOW);
        self::assertSame(VeeamClient::ERROR_INVALID, $invalid['error']);

        foreach ([$refused, $private, $down, $forbidden, $invalid] as $result) {
            self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($result));
            self::assertStringNotContainsString(self::TOKEN, (string) json_encode($result));
        }
    }

    public function testMissingConfigurationCallsNothing(): void
    {
        $client = new VeeamClient('', self::USER, self::PASSWORD, '1.1-rev2', function (): array {
            $this->fail('Aucun appel attendu.');
        });

        self::assertSame(VeeamClient::ERROR_CONFIG, $client->fetch(self::NOW)['error']);
        self::assertSame(
            VeeamClient::ERROR_CONFIG,
            (new VeeamClient(self::BASE, self::USER, '', '1.1-rev2', function (): array {
                $this->fail('Aucun appel attendu.');
            }))->fetch(self::NOW)['error']
        );
    }

    public function testServerAddressIsNormalized(): void
    {
        self::assertSame('https://veeam.exemple.test:9419', VeeamClient::normalizeUrl('veeam.exemple.test'));
        self::assertSame(
            'https://veeam.exemple.test:9419',
            VeeamClient::normalizeUrl('https://VEEAM.exemple.test/api/v1')
        );
        self::assertSame('https://192.0.2.5:443', VeeamClient::normalizeUrl('https://192.0.2.5:443'));
        self::assertNull(VeeamClient::normalizeUrl('http://veeam.exemple.test'));
        self::assertSame(
            'http://veeam.exemple.test:9419',
            VeeamClient::normalizeUrl('http://veeam.exemple.test', true)
        );
        self::assertNull(VeeamClient::normalizeUrl('https://user:secret@veeam.exemple.test'));
        self::assertNull(VeeamClient::normalizeUrl('ftp://veeam.exemple.test'));
        self::assertNull(VeeamClient::normalizeUrl('https://<script>/'));
        self::assertTrue(VeeamClient::isValidApiVersion('1.1-rev2'));
        self::assertFalse(VeeamClient::isValidApiVersion('9.9'));
    }
}

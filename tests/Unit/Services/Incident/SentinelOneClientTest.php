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

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneClient;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneParser;
use GlpiPlugin\Grcmanager\Tests\Mock\MockSentinelOne;
use PHPUnit\Framework\TestCase;

/**
 * Issue #707 : client SentinelOne contre la fausse console (en mémoire, aucun appel réseau).
 * Assertions sur des codes et des nombres, jamais sur des textes traduits.
 */
final class SentinelOneClientTest extends TestCase
{
    private const BASE = 'https://console.example.test';

    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $calls = [];

    private function client(string $token): SentinelOneClient
    {
        $mock = new MockSentinelOne();

        return new SentinelOneClient(self::BASE, $token, function (string $url, array $headers) use ($mock): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers];
            $parts = parse_url($url);
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);

            return $mock->handle('GET', (string) $parts['path'], array_map('strval', $query), $headers);
        });
    }

    public function testConnectionCountsAgentsAndThreatsWithTheTokenInTheHeaderOnly(): void
    {
        $result = $this->client(MockSentinelOne::VIEWER_TOKEN)->testConnection();

        self::assertTrue($result['ok']);
        self::assertSame('', $result['error']);
        self::assertSame(4, $result['agents']);
        self::assertSame(5, $result['threats']);
        self::assertFalse($result['empty']);
        foreach ($this->calls as $call) {
            self::assertSame('ApiToken ' . MockSentinelOne::VIEWER_TOKEN, $call['headers']['Authorization']);
            self::assertStringNotContainsString(MockSentinelOne::VIEWER_TOKEN, $call['url']);
            self::assertStringStartsWith(self::BASE . SentinelOneClient::API_PREFIX . '/', $call['url']);
        }
    }

    public function testConnectionWarnsWhenTheTokenSeesNothing(): void
    {
        $result = $this->client(MockSentinelOne::EMPTY_TOKEN)->testConnection();

        self::assertTrue($result['ok']);
        self::assertTrue($result['empty']);
        self::assertSame(0, $result['agents']);
    }

    public function testRefusedTokenIsReportedWithoutException(): void
    {
        $result = $this->client('wrong-token')->testConnection();

        self::assertFalse($result['ok']);
        self::assertSame(SentinelOneClient::ERROR_UNAUTHORIZED, $result['error']);
    }

    public function testPaginationFollowsTheCursorUntilTheEnd(): void
    {
        $ids = [];
        $fetch = $this->client(MockSentinelOne::VIEWER_TOKEN)->fetchAll(
            'threats',
            ['limit' => '2', 'sortBy' => 'createdAt', 'sortOrder' => 'asc'],
            static function (array $items) use (&$ids): void {
                foreach ($items as $item) {
                    $ids[] = (string) $item['id'];
                }
            }
        );

        self::assertTrue($fetch['complete']);
        self::assertSame(3, $fetch['pages']);
        self::assertSame(5, $fetch['items']);
        self::assertSame(5, count(array_unique($ids)));
        // Pages suivantes demandées avec le curseur reçu.
        self::assertStringNotContainsString('cursor=', $this->calls[0]['url']);
        self::assertStringContainsString('cursor=', $this->calls[1]['url']);
    }

    public function testMaxPagesStopsAndLeavesTheRestForTheNextRun(): void
    {
        $noop = static function (): void {
        };
        $fetch = $this->client(MockSentinelOne::VIEWER_TOKEN)->fetchAll('threats', ['limit' => '1'], $noop, 2);

        self::assertFalse($fetch['complete']);
        self::assertSame(2, $fetch['items']);
        self::assertSame('', $fetch['error']);
    }

    public function testRateLimitStopsTheListWithItsDelay(): void
    {
        $seen = 0;
        $count = static function (array $items) use (&$seen): void {
            $seen += count($items);
        };
        $fetch = $this->client(MockSentinelOne::RATE_LIMIT_TOKEN)->fetchAll('threats', ['limit' => '2'], $count);

        self::assertFalse($fetch['complete']);
        self::assertSame(SentinelOneClient::ERROR_RATE_LIMITED, $fetch['error']);
        self::assertSame(30, $fetch['retry_after']);
        // La première page a bien été traitée avant l'arrêt.
        self::assertSame(2, $seen);
    }

    public function testForbiddenAndMissingFeaturesAreReportedPerList(): void
    {
        $client = $this->client(MockSentinelOne::LICENCE_TOKEN);
        $noop = static function (): void {
        };

        self::assertSame(SentinelOneClient::ERROR_FORBIDDEN, $client->fetchAll('alerts', [], $noop)['error']);
        self::assertSame(SentinelOneClient::ERROR_NOT_FOUND, $client->fetchAll('vulnerabilities', [], $noop)['error']);
        self::assertSame('', $client->fetchAll('threats', [], $noop)['error']);
    }

    public function testOnlyKnownReadEndpointsAreCalled(): void
    {
        $page = $this->client(MockSentinelOne::VIEWER_TOKEN)->page('threats/mitigate/quarantine', []);

        self::assertSame(SentinelOneClient::ERROR_NOT_FOUND, $page['error']);
        self::assertSame([], $this->calls, 'Un chemin hors de la liste fermée ne doit jamais être appelé.');
    }

    public function testCreatedAfterFilterReturnsOnlyNewThreats(): void
    {
        $since = SentinelOneParser::apiDate(gmdate('Y-m-d H:i:s', time() - 40 * 3600));
        $page = $this->client(MockSentinelOne::VIEWER_TOKEN)
            ->page('threats', ['createdAt__gt' => $since, 'limit' => '100']);

        self::assertSame('', $page['error']);
        self::assertSame(3, $page['total']);
    }

    public function testUnreadableAnswerAndUnreachableConsole(): void
    {
        $garbage = new SentinelOneClient(
            self::BASE,
            't',
            static fn (): array => ['status' => 200, 'body' => '<html>', 'retry_after' => 0]
        );
        self::assertSame(SentinelOneClient::ERROR_INVALID, $garbage->page('agents', [])['error']);

        $down = new SentinelOneClient(
            self::BASE,
            't',
            static fn (): array => ['status' => 0, 'body' => '', 'retry_after' => 0, 'error' => 'transport_error: x']
        );
        self::assertSame(SentinelOneClient::ERROR_UNREACHABLE, $down->page('agents', [])['error']);

        $internal = new SentinelOneClient(
            self::BASE,
            't',
            static fn (): array => ['status' => 0, 'body' => '', 'retry_after' => 0, 'error' => 'internal_address']
        );
        self::assertSame(SentinelOneClient::ERROR_INTERNAL, $internal->page('agents', [])['error']);
    }
}

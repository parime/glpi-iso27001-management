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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitor;
use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;

/**
 * Issue #183 : surveillance des fuites de données sur des réponses rejouées de Have I Been Pwned.
 *
 * @phpstan-type Reply array{status: int, body?: string, retry_after?: int}
 */
final class BreachMonitorTest extends GrcmanagerIntegrationTestCase
{
    private const DOMAIN = 'grc-breach-test.example';

    /** @var list<string> Adresses envoyées à l'API */
    private array $queried = [];

    /**
     * @param array<string, Reply|list<Reply>> $responses
     */
    private function client(array $responses): HibpClient
    {
        return new HibpClient('k', function (string $url) use (&$responses): array {
            $path = substr($url, strlen(HibpClient::BASE_URL));
            if (preg_match('#^/breachedaccount/([^?]+)#', $path, $m) === 1) {
                $this->queried[] = rawurldecode($m[1]);
            }
            $response = $responses[$path] ?? ['status' => 404];
            if (array_is_list($response)) {
                // Suite de réponses (429 puis 200…).
                $response = count($responses[$path]) > 1 ? array_shift($responses[$path]) : $responses[$path][0];
            }

            return [
                'status'      => $response['status'],
                'body'        => $response['body'] ?? '',
                'retry_after' => $response['retry_after'] ?? 0,
            ];
        });
    }

    private function userWithEmail(string $email): int
    {
        return $this->createTestUser('Breach', 'Test', ['_useremails' => [-1 => $email]]);
    }

    /**
     * @param list<string> $names
     */
    private static function breachesBody(array $names): string
    {
        return (string) json_encode(array_map(static fn (string $name): array => [
            'Name' => $name, 'Title' => $name . ' title', 'Domain' => strtolower($name) . '.com',
            'BreachDate' => '2020-01-02', 'AddedDate' => '2020-02-03T00:00:00Z',
            'DataClasses' => ['Email addresses', 'Passwords'], 'IsVerified' => true,
        ], $names));
    }

    private static function accountPath(string $email): string
    {
        return '/breachedaccount/' . rawurlencode($email) . '?truncateResponse=false';
    }

    public function testAddressByAddressRecordsOnlyWorkAddressesOnceWithoutTheirEmail(): void
    {
        $jane = $this->userWithEmail('jane@' . self::DOMAIN);
        $this->userWithEmail('bob@' . self::DOMAIN);
        $this->userWithEmail('someone@gmail.com');
        $client = $this->client([
            '/subscribeddomains'                              => ['status' => 401],
            self::accountPath('jane@' . self::DOMAIN)          => [
                'status' => 200,
                'body'   => self::breachesBody(['Adobe', 'Gawker']),
            ],
        ]);

        $result = (new BreachMonitor($client))->run([self::DOMAIN]);

        self::assertSame('', $result->error);
        self::assertSame(2, $result->created);
        self::assertSame(0, $result->cursor, 'Liste parcourue jusqu\'au bout.');
        self::assertNotContains('someone@gmail.com', $this->queried, 'Adresse personnelle jamais envoyée.');
        $rows = (new BreachExposure())->find(['users_id' => $jane]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('', $row['email'], 'Adresse non stockée quand un utilisateur la porte.');
            self::assertSame(BreachExposure::STATUS_NEW, $row['status']);
        }

        // Relancé : aucun doublon, et le statut posé par le RSSI est conservé.
        $first = (int) array_key_first($rows);
        (new BreachExposure())->update(['id' => $first, 'status' => BreachExposure::STATUS_HANDLED]);
        $again = (new BreachMonitor($client))->run([self::DOMAIN]);
        self::assertSame(0, $again->created);
        $kept = (new BreachExposure())->find(['id' => $first])[$first];
        self::assertSame(BreachExposure::STATUS_HANDLED, $kept['status']);
    }

    public function testRateLimitWaitsWithinBudgetThenResumesLater(): void
    {
        $first = $this->userWithEmail('a@' . self::DOMAIN);
        $this->userWithEmail('b@' . self::DOMAIN);
        $waits = [];
        $sleep = static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        };

        // Attente comprise dans le budget : on attend, puis on continue.
        $client = $this->client([
            '/subscribeddomains'                  => ['status' => 401],
            self::accountPath('a@' . self::DOMAIN) => [['status' => 429, 'retry_after' => 2], ['status' => 404]],
        ]);
        $result = (new BreachMonitor($client, 10, $sleep))->run([self::DOMAIN]);
        self::assertSame([2], $waits);
        self::assertFalse($result->rateLimited);

        // Attente hors budget : arrêt, curseur sur le dernier utilisateur entièrement vérifié.
        $client = $this->client([
            '/subscribeddomains'                  => ['status' => 401],
            self::accountPath('b@' . self::DOMAIN) => ['status' => 429, 'retry_after' => 3600],
        ]);
        $result = (new BreachMonitor($client, 10, $sleep))->run([self::DOMAIN]);
        self::assertTrue($result->rateLimited);
        self::assertSame($first, $result->cursor, 'Reprise après le premier utilisateur, déjà vérifié.');
    }

    public function testRefusedKeyStopsTheRun(): void
    {
        $this->userWithEmail('c@' . self::DOMAIN);
        $client = $this->client([
            '/subscribeddomains'                  => ['status' => 401],
            self::accountPath('c@' . self::DOMAIN) => ['status' => 401],
        ]);

        $result = (new BreachMonitor($client))->run([self::DOMAIN]);

        self::assertSame('unauthorized', $result->error);
        self::assertSame(0, $result->created);
    }

    public function testVerifiedDomainUsesOneRequestAndKeepsUnknownAddresses(): void
    {
        $jane = $this->userWithEmail('jane@' . self::DOMAIN);
        $client = $this->client([
            '/subscribeddomains'               => [
                'status' => 200,
                'body'   => json_encode([['DomainName' => self::DOMAIN]]),
            ],
            '/breacheddomain/' . self::DOMAIN  => [
                'status' => 200,
                'body'   => json_encode(['jane' => ['Adobe'], 'shared' => ['Adobe']]),
            ],
            '/breaches'                        => ['status' => 200, 'body' => self::breachesBody(['Adobe'])],
        ]);

        $result = (new BreachMonitor($client))->run([self::DOMAIN]);

        self::assertSame(2, $result->created);
        self::assertSame([], $this->queried, 'Aucune adresse envoyée : seul le domaine part.');
        self::assertCount(1, (new BreachExposure())->find(['users_id' => $jane, 'breach_name' => 'Adobe']));
        $shared = (new BreachExposure())->find(['users_id' => 0, 'email' => 'shared@' . self::DOMAIN]);
        self::assertCount(1, $shared, 'Adresse sans utilisateur GLPI : gardée pour pouvoir agir.');
        self::assertSame('Adobe title', current($shared)['breach_title']);
    }

    public function testOnlyStatusAndCommentCanBeChangedAndNothingIsCreatedByHand(): void
    {
        global $DB;

        $DB->insert(BreachExposure::getTable(), [
            'users_id' => 2, 'email' => '', 'breach_name' => 'Adobe', 'breach_title' => 'Adobe',
            'status' => BreachExposure::STATUS_NEW, 'data_classes' => '[]',
        ]);
        $id = (int) $DB->insertId();

        (new BreachExposure())->update([
            'id' => $id, 'status' => 'bogus', 'breach_title' => 'tampered', 'users_id' => 3, 'comment' => 'MFA enabled',
        ]);
        $row = (new BreachExposure())->find(['id' => $id])[$id];

        self::assertSame('Adobe', $row['breach_title']);
        self::assertSame(2, (int) $row['users_id']);
        self::assertSame(BreachExposure::STATUS_NEW, $row['status'], 'Statut inconnu refusé.');
        self::assertSame('MFA enabled', $row['comment']);
        self::assertFalse(BreachExposure::canCreate());
    }

    public function testExposuresOfDeletedUsersArePurged(): void
    {
        global $DB;

        $DB->insert(BreachExposure::getTable(), [
            'users_id' => 99999999, 'email' => '', 'breach_name' => 'Adobe', 'status' => BreachExposure::STATUS_NEW,
            'data_classes' => '[]',
        ]);
        (new BreachMonitor($this->client([])))->run([]);

        self::assertSame(0, countElementsInTable(BreachExposure::getTable(), ['users_id' => 99999999]));
    }
}

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

use GlpiPlugin\Grcmanager\Services\Access\BitwardenPublicClient as Client;
use PHPUnit\Framework\TestCase;

/**
 * Issue #859 : lecture des membres d'une organisation Bitwarden par un faux serveur en mémoire (aucun
 * appel réseau). Les réponses reprennent le format documenté de l'API publique (modèles
 * MemberResponseModel et ListResponseModel du dépôt bitwarden/server, valeurs d'exemple de leurs
 * commentaires). Assertions sur des codes et des nombres, jamais sur des textes traduits.
 */
final class BitwardenPublicClientTest extends TestCase
{
    private const CLIENT_ID = 'organization.11111111-2222-3333-4444-555555555555';
    private const SECRET = 'secret-fictif';
    private const TOKEN = 'jeton-fictif';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    private function client(
        int $membersStatus = 200,
        ?string $membersBody = null,
        int $tokenStatus = 200,
        string $api = 'https://api.bitwarden.com',
        string $identity = 'https://identity.bitwarden.com'
    ): Client {
        $membersBody ??= (string) json_encode(self::members());

        return new Client(
            $api,
            $identity,
            self::CLIENT_ID,
            self::SECRET,
            function (string $method, string $url, array $headers, ?string $body) use (
                $membersStatus,
                $membersBody,
                $tokenStatus
            ): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                if ($method === 'POST') {
                    return [
                        'status'      => $tokenStatus,
                        'body'        => $tokenStatus === 200
                            ? '{"access_token":"' . self::TOKEN . '","expires_in":3600,"token_type":"Bearer"}'
                            : '{"error":"invalid_client"}',
                        'retry_after' => 0,
                    ];
                }

                return ['status' => $membersStatus, 'body' => $membersBody, 'retry_after' => 0];
            }
        );
    }

    /**
     * Réponse de GET /public/members, au format documenté.
     *
     * @return array<string, mixed>
     */
    private static function members(): array
    {
        return [
            'object' => 'list',
            'data'   => [
                [
                    'object'                => 'member',
                    'id'                    => '539a36c5-e0d2-4cf9-979e-51ecf5cf6593',
                    'userId'                => '48b47ee1-493e-4c67-aef7-014996c40eca',
                    'name'                  => 'John Smith',
                    'email'                 => 'JSmith@example.com',
                    'twoFactorEnabled'      => false,
                    'status'                => 2,
                    'type'                  => 1,
                    'externalId'            => 'external_id_123456',
                    'resetPasswordEnrolled' => false,
                    'collections'           => [
                        [
                            'id'            => 'bfbc8338-e329-4dc0-b0c9-317c2ebf1a09',
                            'readOnly'      => false,
                            'hidePasswords' => false,
                            'manage'        => false,
                        ],
                    ],
                ],
                [
                    'object'           => 'member',
                    'id'               => '00000000-0000-0000-0000-000000000002',
                    'userId'           => null,
                    'email'            => 'invite@example.com',
                    'twoFactorEnabled' => false,
                    'status'           => 0,
                    'type'             => 2,
                    'collections'      => [],
                ],
                [
                    'object'           => 'member',
                    'id'               => '00000000-0000-0000-0000-000000000003',
                    'email'            => 'ancien@example.com',
                    'twoFactorEnabled' => true,
                    'status'           => -1,
                    'type'             => 0,
                ],
                // Ligne sans adresse : ignorée.
                ['object' => 'member', 'id' => '00000000-0000-0000-0000-000000000004'],
            ],
        ];
    }

    public function testEndpointsOfCloudAndSelfHostedServers(): void
    {
        self::assertSame(
            ['api' => 'https://api.bitwarden.eu', 'identity' => 'https://identity.bitwarden.eu'],
            Client::endpoints(Client::SERVER_EU, '')
        );
        self::assertSame(
            ['api' => 'https://vault.example.test/api', 'identity' => 'https://vault.example.test/identity'],
            Client::endpoints(Client::SERVER_SELF, 'https://vault.example.test/api/')
        );
        // http seulement si les adresses internes sont autorisées ; jamais d'identifiants ni de paramètres.
        self::assertSame('', Client::endpoints(Client::SERVER_SELF, 'http://vault.example.test')['api']);
        self::assertSame(
            'http://vault.example.test:8080/api',
            Client::endpoints(Client::SERVER_SELF, 'http://vault.example.test:8080', true)['api']
        );
        self::assertNull(Client::normalizeBaseUrl('https://user:pass@vault.example.test'));
        self::assertNull(Client::normalizeBaseUrl('https://vault.example.test/?x=1'));
        self::assertNull(Client::normalizeBaseUrl('ftp://vault.example.test'));
    }

    public function testClientIdFormat(): void
    {
        self::assertTrue(Client::isValidClientId(self::CLIENT_ID));
        self::assertFalse(Client::isValidClientId('user.11111111-2222-3333-4444-555555555555'));
        self::assertFalse(Client::isValidClientId('11111111-2222-3333-4444-555555555555'));
    }

    public function testFetchMembersSendsTheDocumentedRequests(): void
    {
        $fetch = $this->client()->fetchMembers();

        self::assertSame('', $fetch['error']);
        self::assertTrue($fetch['complete']);
        self::assertCount(2, $this->calls);
        // Jeton : formulaire client_credentials, portée api.organization.
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://identity.bitwarden.com/connect/token', $this->calls[0]['url']);
        parse_str((string) $this->calls[0]['body'], $form);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('api.organization', $form['scope']);
        self::assertSame(self::CLIENT_ID, $form['client_id']);
        self::assertSame(self::SECRET, $form['client_secret']);
        // Membres : GET seulement, jeton en en-tête, jamais dans l'adresse.
        self::assertSame('GET', $this->calls[1]['method']);
        self::assertSame('https://api.bitwarden.com/public/members', $this->calls[1]['url']);
        self::assertSame('Bearer ' . self::TOKEN, $this->calls[1]['headers']['Authorization']);
        foreach ($this->calls as $call) {
            self::assertStringNotContainsString(self::SECRET, $call['url']);
        }
    }

    public function testFetchMembersMapsRolesStatusesAndCollections(): void
    {
        $members = $this->client()->fetchMembers()['members'];

        self::assertCount(3, $members);
        self::assertSame('jsmith@example.com', $members[0]['email']);
        self::assertSame('admin', $members[0]['role']);
        self::assertSame('active', $members[0]['status']);
        self::assertFalse($members[0]['two_factor']);
        self::assertSame(['bfbc8338-e329-4dc0-b0c9-317c2ebf1a09'], $members[0]['collections']);
        self::assertSame('user', $members[1]['role']);
        self::assertSame('invited', $members[1]['status']);
        self::assertSame('owner', $members[2]['role']);
        self::assertSame('revoked', $members[2]['status']);
        self::assertTrue($members[2]['two_factor']);
    }

    public function testErrors(): void
    {
        self::assertSame(Client::ERROR_UNAUTHORIZED, $this->client(200, null, 400)->fetchMembers()['error']);
        self::assertSame(Client::ERROR_FORBIDDEN, $this->client(403)->fetchMembers()['error']);
        self::assertSame(Client::ERROR_NOT_FOUND, $this->client(404)->fetchMembers()['error']);
        self::assertSame(Client::ERROR_RATE_LIMITED, $this->client(429)->fetchMembers()['error']);
        self::assertSame(Client::ERROR_UNREACHABLE, $this->client(0)->fetchMembers()['error']);
        $invalid = $this->client(200, '<html>login</html>')->fetchMembers();
        self::assertSame(Client::ERROR_INVALID, $invalid['error']);
        self::assertFalse($invalid['complete']);
        // Non configuré : aucun appel.
        $this->calls = [];
        self::assertSame(Client::ERROR_CONFIG, $this->client(200, null, 200, '', '')->fetchMembers()['error']);
        self::assertSame([], $this->calls);
    }

    public function testConnectionTestOnlyAsksForAToken(): void
    {
        self::assertSame('', $this->client()->test());
        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame(Client::ERROR_UNAUTHORIZED, $this->client(200, null, 401)->test());
    }
}

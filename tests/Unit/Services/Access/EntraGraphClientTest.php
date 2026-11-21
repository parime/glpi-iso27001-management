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

use GlpiPlugin\Grcmanager\Services\Access\EntraGraphClient;
use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #852 : lecture d'Entra ID par un faux Microsoft Graph (en mémoire, aucun appel réseau).
 * Assertions sur des codes et des nombres, jamais sur des textes traduits.
 */
final class EntraGraphClientTest extends TestCase
{
    private const TENANT = 'exemple.onmicrosoft.com';
    private const CLIENT = '11111111-2222-3333-4444-555555555555';
    private const SECRET = 'secret-fictif';
    private const TOKEN = 'jeton-fictif';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /**
     * @param list<array{status: int, body: string}> $pages réponses successives du rapport
     */
    private function client(array $pages, int $tokenStatus = 200, string $secret = self::SECRET): EntraGraphClient
    {
        return new EntraGraphClient(
            self::TENANT,
            self::CLIENT,
            $secret,
            function (string $method, string $url, array $headers, ?string $body) use (&$pages, $tokenStatus): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                if ($method === 'POST') {
                    return [
                        'status'      => $tokenStatus,
                        'body'        => $tokenStatus === 200 ? '{"access_token":"' . self::TOKEN . '"}' : '{}',
                        'retry_after' => 0,
                    ];
                }
                $page = array_shift($pages) ?? ['status' => 500, 'body' => ''];

                return $page + ['retry_after' => 0];
            }
        );
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private static function page(array $value, ?string $next = null): array
    {
        $body = ['value' => $value];
        if ($next !== null) {
            $body['@odata.nextLink'] = $next;
        }

        return ['status' => 200, 'body' => (string) json_encode($body)];
    }

    public function testReadsEveryPageWithTheTokenInTheHeaderOnly(): void
    {
        $result = $this->client([
            self::page(
                [['userPrincipalName' => 'a.demo@exemple.test', 'methodsRegistered' => ['fido2']]],
                EntraGraphClient::REPORT_URL . '?$skiptoken=page2'
            ),
            self::page([['userPrincipalName' => 'b.demo@exemple.test', 'methodsRegistered' => []]]),
        ])->fetchRegistrationDetails();

        self::assertSame('', $result['error']);
        self::assertTrue($result['complete']);
        self::assertSame(2, $result['pages']);
        self::assertCount(2, $result['items']);

        self::assertCount(3, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertStringStartsWith(EntraGraphClient::LOGIN_BASE . self::TENANT . '/', $this->calls[0]['url']);
        self::assertStringContainsString('grant_type=client_credentials', (string) $this->calls[0]['body']);
        foreach (array_slice($this->calls, 1) as $call) {
            self::assertSame('GET', $call['method']);
            self::assertStringStartsWith(EntraGraphClient::REPORT_URL, $call['url']);
            self::assertSame('Bearer ' . self::TOKEN, $call['headers']['Authorization']);
            self::assertNull($call['body']);
        }
        foreach ($this->calls as $call) {
            self::assertStringNotContainsString(self::SECRET, $call['url']);
            self::assertStringNotContainsString(self::TOKEN, $call['url']);
        }
    }

    public function testNextLinkToAnotherHostIsNotFollowed(): void
    {
        $result = $this->client([
            self::page([['userPrincipalName' => 'a.demo@exemple.test']], 'https://attaquant.example.test/steal'),
        ])->fetchRegistrationDetails();

        self::assertTrue($result['complete']);
        self::assertSame(1, $result['pages']);
        self::assertCount(2, $this->calls);
    }

    public function testPageLimitLeavesTheReadIncomplete(): void
    {
        $loop = self::page([['userPrincipalName' => 'a.demo@exemple.test']], EntraGraphClient::REPORT_URL . '?n');
        $result = $this->client([$loop, $loop, $loop])->fetchRegistrationDetails(2);

        self::assertFalse($result['complete']);
        self::assertSame('', $result['error']);
        self::assertSame(2, $result['pages']);
    }

    public function testErrorsAreCodesWithoutException(): void
    {
        self::assertSame(
            EntraGraphClient::ERROR_UNAUTHORIZED,
            $this->client([], 400)->fetchRegistrationDetails()['error']
        );
        self::assertSame(
            EntraGraphClient::ERROR_FORBIDDEN,
            $this->client([['status' => 403, 'body' => '']])->fetchRegistrationDetails()['error']
        );
        self::assertSame(
            EntraGraphClient::ERROR_RATE_LIMITED,
            $this->client([['status' => 429, 'body' => '']])->fetchRegistrationDetails()['error']
        );
        self::assertSame(
            EntraGraphClient::ERROR_INVALID,
            $this->client([['status' => 200, 'body' => 'not json']])->fetchRegistrationDetails()['error']
        );
        self::assertSame(EntraGraphClient::ERROR_UNREACHABLE, EntraGraphClient::errorOf(0));

        $this->calls = [];
        self::assertSame(
            EntraGraphClient::ERROR_CONFIG,
            $this->client([], 200, '')->fetchRegistrationDetails()['error']
        );
        self::assertSame([], $this->calls, 'Aucun appel sans secret.');
    }

    public function testIdentifiersAreValidated(): void
    {
        self::assertTrue(EntraGraphClient::isValidTenant('exemple.onmicrosoft.com'));
        self::assertTrue(EntraGraphClient::isValidTenant(self::CLIENT));
        self::assertFalse(EntraGraphClient::isValidTenant('exemple/../x'));
        self::assertFalse(EntraGraphClient::isValidTenant(''));
        self::assertTrue(EntraGraphClient::isValidClientId(self::CLIENT));
        self::assertFalse(EntraGraphClient::isValidClientId('exemple.onmicrosoft.com'));
    }

    public function testRowsUseTheCsvImportShape(): void
    {
        $rows = EntraGraphClient::rows([
            [
                'userPrincipalName' => 'a.demo@exemple.test',
                'methodsRegistered' => ['microsoftAuthenticatorPush', 'mobilePhone'],
                'isMfaRegistered'   => true,
                'isAdmin'           => true,
            ],
            ['userPrincipalName' => 'b.demo@exemple.test', 'methodsRegistered' => ['mobilePhone']],
            ['userPrincipalName' => '', 'methodsRegistered' => ['fido2']],
            ['methodsRegistered' => 'not a list'],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('a.demo@exemple.test', $rows[0]['email']);
        self::assertTrue($rows[0]['admin']);
        self::assertTrue($rows[0]['mfa']);
        self::assertNull($rows[1]['admin']);
        self::assertNull($rows[1]['remote']);
        self::assertSame(MfaCoverageRules::LEVEL_STRONG, MfaCoverageRules::level($rows[0]['methods']));
        self::assertSame(MfaCoverageRules::LEVEL_WEAK, MfaCoverageRules::level($rows[1]['methods']));
    }
}

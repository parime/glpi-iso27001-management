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
use PHPUnit\Framework\TestCase;

/**
 * Issue #1043 : bouton « Tester la connexion » de Microsoft Graph, sur un faux serveur en mémoire
 * (aucun appel réseau) : seule la demande de jeton part, rien n'est lu dans Entra ID.
 */
final class EntraGraphConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function client(int $status, string $body = ''): EntraGraphClient
    {
        return new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            function (string $method, string $url) use ($status, $body): array {
                $this->calls[] = $method . ' ' . $url;

                return ['status' => $status, 'body' => $body, 'retry_after' => 0];
            }
        );
    }

    public function testTestOnlyRequestsAToken(): void
    {
        $error = $this->client(200, (string) json_encode(['access_token' => 'jeton-fictif']))->test();

        $this->assertSame('', $error);
        $this->assertCount(1, $this->calls);
        $this->assertStringStartsWith('POST ' . EntraGraphClient::LOGIN_BASE, $this->calls[0]);
    }

    public function testTestReportsRefusedCredentials(): void
    {
        $this->assertSame(EntraGraphClient::ERROR_UNAUTHORIZED, $this->client(401)->test());
        $this->assertSame(EntraGraphClient::ERROR_UNAUTHORIZED, $this->client(400)->test());
    }

    public function testTestWithoutSecretSendsNothing(): void
    {
        $client = new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            '',
            function (): array {
                $this->calls[] = 'appel';

                return ['status' => 200, 'body' => '', 'retry_after' => 0];
            }
        );

        $this->assertSame(EntraGraphClient::ERROR_CONFIG, $client->test());
        $this->assertSame([], $this->calls);
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\CodeSecurity;

use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityClient;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1043 : bouton « Tester la connexion » de GitHub et GitLab, sur de faux serveurs en mémoire
 * (aucun appel réseau) : une seule requête GET par fournisseur, aucune alerte lue.
 */
final class CodeSecurityConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @param array<string, int> $statuses hôte => statut HTTP */
    private function client(
        array $statuses,
        string $githubToken = 'jeton-fictif',
        string $gitlabToken = 'jeton-fictif'
    ): CodeSecurityClient {
        return new CodeSecurityClient(
            CodeSecurityClient::GITHUB_API,
            $githubToken,
            'https://gitlab.example.test',
            $gitlabToken,
            function (string $method, string $url) use ($statuses): array {
                $this->calls[] = $method . ' ' . $url;
                $host = (string) parse_url($url, PHP_URL_HOST);

                return ['status' => $statuses[$host] ?? 200, 'body' => '{}', 'retry_after' => 0];
            }
        );
    }

    public function testTestSendsOneReadPerProvider(): void
    {
        $result = $this->client([])->test();

        $this->assertSame([Rules::PROVIDER_GITHUB => '', Rules::PROVIDER_GITLAB => ''], $result);
        $this->assertSame([
            'GET ' . CodeSecurityClient::GITHUB_API . '/rate_limit',
            'GET https://gitlab.example.test/api/v4/version',
        ], $this->calls);
    }

    public function testTestReportsARefusedToken(): void
    {
        $result = $this->client(['api.github.com' => 401, 'gitlab.example.test' => 403])->test();

        $this->assertSame(CodeSecurityClient::ERROR_UNAUTHORIZED, $result[Rules::PROVIDER_GITHUB]);
        $this->assertSame(CodeSecurityClient::ERROR_UNAUTHORIZED, $result[Rules::PROVIDER_GITLAB]);
    }

    public function testTestSkipsAProviderWithoutToken(): void
    {
        $result = $this->client(['gitlab.example.test' => 500], '')->test();

        $this->assertSame([Rules::PROVIDER_GITLAB => CodeSecurityClient::ERROR_STATUS], $result);
        $this->assertCount(1, $this->calls);
    }
}

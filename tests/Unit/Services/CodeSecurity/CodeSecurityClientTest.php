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
 * Issue #858 : client en lecture seule de GitHub et GitLab, sur de faux serveurs (aucun appel réseau).
 * Les jetons sont construits au moment du test et ne ressemblent à aucun vrai jeton.
 */
final class CodeSecurityClientTest extends TestCase
{
    private const GITLAB = 'https://gitlab.example.test';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    private static function token(): string
    {
        return implode('-', ['jeton', 'fictif', 'lecture']);
    }

    /**
     * @param array<string, array{0: int, 1: string}> $answers chemin => [statut, corps]
     */
    private function client(array $answers): CodeSecurityClient
    {
        $this->calls = [];

        return new CodeSecurityClient(
            CodeSecurityClient::GITHUB_API,
            self::token(),
            self::GITLAB,
            self::token(),
            function (string $method, string $url, array $headers, ?string $body) use ($answers): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                $path = (string) parse_url($url, PHP_URL_PATH);
                [$status, $answer] = $answers[$path] ?? [404, '{"message":"Not Found"}'];

                return ['status' => $status, 'body' => $answer, 'retry_after' => 0];
            }
        );
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    private static function github(): array
    {
        $base = '/repos/example/portal/';

        return [
            $base . 'dependabot/alerts'      => [200, (string) json_encode([[
                'number' => 4, 'state' => 'open', 'created_at' => '2026-01-10T08:00:00Z',
                'html_url' => 'https://github.com/example/portal/security/dependabot/4',
                'dependency' => ['package' => ['ecosystem' => 'npm', 'name' => 'left-pad']],
                'security_advisory' => [
                    'summary' => 'Prototype pollution', 'severity' => 'high', 'cve_id' => 'CVE-2026-0858',
                ],
            ]])],
            $base . 'secret-scanning/alerts' => [200, (string) json_encode([[
                'number' => 2, 'state' => 'open', 'created_at' => '2026-02-01T08:00:00Z',
                'html_url' => 'https://github.com/example/portal/security/secret-scanning/2',
                'secret_type_display_name' => 'Example service token', 'validity' => 'active',
                // Valeur fictive : elle ne doit jamais être gardée.
                'secret' => str_repeat('x', 12), 'push_protection_bypassed' => true,
            ]])],
            // Code scanning non activé (GitHub Advanced Security absent).
            $base . 'code-scanning/alerts'   => [403, '{"message":"Advanced Security must be enabled"}'],
        ];
    }

    public function testOnlyGithubReadPaths(): void
    {
        $this->assertTrue(CodeSecurityClient::isGithubReadPath('/repos/a/b/dependabot/alerts'));
        $this->assertTrue(CodeSecurityClient::isGithubReadPath('/repos/a/b/secret-scanning/alerts'));
        $this->assertFalse(CodeSecurityClient::isGithubReadPath('/repos/a/b/dependabot/alerts/1'));
        $this->assertFalse(CodeSecurityClient::isGithubReadPath('/repos/a/b/hooks'));
        $this->assertFalse(CodeSecurityClient::isGithubReadPath('/repos/a/../b/code-scanning/alerts/x'));
        // La requête GitLab est une lecture, jamais une mutation.
        $this->assertStringStartsWith('query', CodeSecurityClient::GITLAB_QUERY);
        $this->assertStringNotContainsStringIgnoringCase('mutation', CodeSecurityClient::GITLAB_QUERY);
    }

    public function testGithubAlertsAreReadWithGetOnly(): void
    {
        $client = $this->client(self::github());
        $result = $client->fetch(Rules::PROVIDER_GITHUB, 'example/portal');

        $this->assertTrue($result['complete']);
        $this->assertCount(3, $this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('GET', $call['method']);
            $this->assertNull($call['body']);
            $this->assertStringContainsString('state=open', $call['url']);
            // Le jeton n'est jamais dans l'adresse.
            $this->assertStringNotContainsString(self::token(), $call['url']);
            $this->assertSame('Bearer ' . self::token(), $call['headers']['Authorization']);
        }
        $this->assertStringContainsString('hide_secret=true', $this->calls[1]['url']);
        $this->assertSame(Rules::SCAN_OK, $result['scans'][Rules::CATEGORY_DEPENDENCY]);
        $this->assertSame(Rules::SCAN_OK, $result['scans'][Rules::CATEGORY_SECRET]);
        $this->assertSame(Rules::SCAN_UNAVAILABLE, $result['scans'][Rules::CATEGORY_CODE]);

        $byCategory = array_column($result['alerts'], null, 'category');
        $dependency = $byCategory[Rules::CATEGORY_DEPENDENCY];
        $this->assertSame(Rules::HIGH, $dependency['severity']);
        $this->assertSame('npm left-pad', $dependency['detail']);
        $this->assertSame('CVE-2026-0858', $dependency['reference']);
        $this->assertSame('2026-01-10 08:00:00', $dependency['detected']);
        $secret = $byCategory[Rules::CATEGORY_SECRET];
        $this->assertSame(Rules::CRITICAL, $secret['severity']);
        $this->assertSame(Rules::VALIDITY_ACTIVE, $secret['validity']);
        $this->assertSame('push_protection_bypassed', $secret['detail']);
        // Aucune trace de la valeur du secret.
        $this->assertStringNotContainsString(str_repeat('x', 12), (string) json_encode($result));
    }

    public function testGithubErrorsStopTheRepository(): void
    {
        $unauthorized = $this->client(['/repos/example/portal/dependabot/alerts' => [401, '{}']]);
        $result = $unauthorized->fetch(Rules::PROVIDER_GITHUB, 'example/portal');
        $this->assertFalse($result['complete']);
        $this->assertSame(CodeSecurityClient::ERROR_UNAUTHORIZED, $result['error']);

        $limited = $this->client([
            '/repos/example/portal/dependabot/alerts' => [403, '{"message":"API rate limit exceeded"}'],
        ]);
        $error = $limited->fetch(Rules::PROVIDER_GITHUB, 'example/portal')['error'];
        $this->assertSame(CodeSecurityClient::ERROR_RATE_LIMIT, $error);

        $invalid = $this->client(['/repos/example/portal/dependabot/alerts' => [200, '<html>']]);
        $error = $invalid->fetch(Rules::PROVIDER_GITHUB, 'example/portal')['error'];
        $this->assertSame(CodeSecurityClient::ERROR_INVALID, $error);
    }

    public function testGitlabSendsTheReadQueryOnly(): void
    {
        $answer = (string) json_encode(['data' => ['project' => [
            'securityScanners' => ['enabled' => ['SAST', 'SECRET_DETECTION']],
            'vulnerabilities'  => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [
                ['id' => 'gid://gitlab/Vulnerability/71', 'title' => 'SQL injection', 'severity' => 'CRITICAL',
                    'reportType' => 'SAST', 'state' => 'DETECTED', 'detectedAt' => '2026-01-05T10:00:00Z',
                    'webUrl' => self::GITLAB . '/team/portal/-/security/vulnerabilities/71'],
                ['id' => 'gid://gitlab/Vulnerability/72', 'title' => 'Example key', 'severity' => 'HIGH',
                    'reportType' => 'SECRET_DETECTION', 'state' => 'CONFIRMED', 'detectedAt' => '2026-02-05T10:00:00Z'],
                ['id' => 'gid://gitlab/Vulnerability/73', 'title' => 'Fixed one', 'severity' => 'LOW',
                    'reportType' => 'SAST', 'state' => 'RESOLVED'],
            ]],
        ]]]);
        $client = $this->client(['/api/graphql' => [200, $answer]]);
        $result = $client->fetch(Rules::PROVIDER_GITLAB, 'team/portal');

        $this->assertTrue($result['complete']);
        $this->assertCount(1, $this->calls);
        $this->assertSame('POST', $this->calls[0]['method']);
        $this->assertSame(self::GITLAB . '/api/graphql', $this->calls[0]['url']);
        $body = json_decode((string) $this->calls[0]['body'], true);
        $this->assertSame(CodeSecurityClient::GITLAB_QUERY, $body['query']);
        $this->assertSame(['fullPath' => 'team/portal'], $body['variables']);

        $this->assertCount(2, $result['alerts']);
        $this->assertSame(Rules::CATEGORY_CODE, $result['alerts'][0]['category']);
        $this->assertSame(Rules::CRITICAL, $result['alerts'][0]['severity']);
        $this->assertSame(Rules::CATEGORY_SECRET, $result['alerts'][1]['category']);
        $this->assertSame(Rules::CRITICAL, $result['alerts'][1]['severity']);
        $this->assertSame(Rules::SCAN_UNAVAILABLE, $result['scans'][Rules::CATEGORY_DEPENDENCY]);
        $this->assertSame(Rules::SCAN_OK, $result['scans'][Rules::CATEGORY_CODE]);
    }

    public function testGitlabWithoutVulnerabilityReport(): void
    {
        $client = $this->client(['/api/graphql' => [200, '{"data":{"project":null}}']]);
        $result = $client->fetch(Rules::PROVIDER_GITLAB, 'team/portal');
        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['alerts']);
        $this->assertSame(array_fill_keys(Rules::CATEGORIES, Rules::SCAN_UNAVAILABLE), $result['scans']);
        // Chemin de projet refusé avant tout appel.
        $this->assertSame(CodeSecurityClient::ERROR_REFUSED, $client->fetch(Rules::PROVIDER_GITLAB, 'a/../b')['error']);
    }

    public function testProviderWithoutTokenIsNotCalled(): void
    {
        $client = new CodeSecurityClient(CodeSecurityClient::GITHUB_API, '', '', '', static function (): array {
            throw new \LogicException('aucun appel attendu');
        });
        $this->assertFalse($client->hasProvider(Rules::PROVIDER_GITHUB));
        $this->assertSame(CodeSecurityClient::ERROR_CONFIG, $client->fetch(Rules::PROVIDER_GITHUB, 'a/b')['error']);
    }
}

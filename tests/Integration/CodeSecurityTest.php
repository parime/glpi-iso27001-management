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

use Appliance;
use GlpiPlugin\Grcmanager\ApplicationSecurity;
use GlpiPlugin\Grcmanager\CodeSecurity;
use GlpiPlugin\Grcmanager\Feature\CodeSecurityFeature;
use GlpiPlugin\Grcmanager\Install\CodeSecurityTables;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityClient;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityConfig;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;

/**
 * Issue #858 : alertes de sécurité du code lues dans de faux serveurs GitHub et GitLab (aucun appel
 * réseau), pour des applications fictives d'une entité de test (seule entité active) et d'une entité
 * cachée. Les jetons sont construits au moment du test.
 */
final class CodeSecurityTest extends GrcmanagerIntegrationTestCase
{
    private const GITLAB = 'https://gitlab.example.test';

    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $apps = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        CodeSecurity::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Code ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo Code cachée ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$this->entity]);
        $_SESSION['glpiactive_entity'] = $this->entity;

        $s = $this->suffix;
        $add = function (string $key, int $entity, string $repository): void {
            $id = (int) (new Appliance())->add(['name' => 'code-' . $key . $this->suffix, 'entities_id' => $entity]);
            $this->assertGreaterThan(0, $id);
            $this->assertNull(ApplicationSecurity::saveFor($id, [
                'repository_url' => $repository, 'environments_separated' => 'yes',
            ]));
            $this->apps[$key] = $id;
        };
        $add('github', $this->entity, 'https://github.com/example/portal' . $s);
        $add('gitlab', $this->entity, 'git@gitlab.example.test:team/app' . $s . '.git');
        $add('other', $this->entity, 'https://bitbucket.example.test/team/other' . $s);
        $add('hidden', $this->hiddenEntity, 'https://github.com/example/hidden' . $s);

        $saved = CodeSecurityConfig::save('', '', false, self::GITLAB, '', false, false);
        $this->assertTrue($saved['saved']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        CodeSecurity::reset();
        parent::tearDown();
    }

    private static function token(): string
    {
        return implode('-', ['jeton', 'fictif', 'integration']);
    }

    /**
     * Faux GitHub : une dépendance vulnérable ouverte depuis 200 jours (hors délai), un secret actif
     * et un secret révoqué, code scanning non activé. Faux GitLab : une faiblesse SAST. $status : code
     * renvoyé à toutes les requêtes (200 : réponses normales).
     */
    private function client(int $status = 200): CodeSecurityClient
    {
        $s = $this->suffix;
        $now = (int) strtotime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $iso = static fn (int $daysAgo): string => gmdate('Y-m-d\TH:i:s\Z', $now - $daysAgo * 86400);
        $base = '/repos/example/portal' . $s . '/';
        $answers = [
            $base . 'dependabot/alerts'      => [200, (string) json_encode([[
                'number' => 11, 'state' => 'open', 'created_at' => $iso(200),
                'html_url' => 'https://github.com/example/portal' . $s . '/security/dependabot/11',
                'dependency' => ['package' => ['ecosystem' => 'composer', 'name' => 'example/lib']],
                'security_advisory' => ['summary' => 'Remote code execution ' . $s, 'severity' => 'critical'],
            ]])],
            $base . 'secret-scanning/alerts' => [200, (string) json_encode([
                ['number' => 3, 'state' => 'open', 'created_at' => $iso(2), 'validity' => 'active',
                    'secret_type_display_name' => 'Example cloud key ' . $s],
                ['number' => 4, 'state' => 'open', 'created_at' => $iso(40), 'validity' => 'inactive',
                    'secret_type_display_name' => 'Example old key ' . $s],
            ])],
            $base . 'code-scanning/alerts'   => [404, '{"message":"no analysis found"}'],
            '/api/graphql'                   => [200, (string) json_encode(['data' => ['project' => [
                'securityScanners' => ['enabled' => ['SAST', 'DEPENDENCY_SCANNING', 'SECRET_DETECTION']],
                'vulnerabilities'  => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                    'id' => 'gid://gitlab/Vulnerability/90', 'title' => 'Cross-site scripting ' . $s,
                    'severity' => 'MEDIUM', 'reportType' => 'SAST', 'state' => 'DETECTED', 'detectedAt' => $iso(10),
                ]]],
            ]]])],
        ];
        $this->calls = [];

        return new CodeSecurityClient(
            CodeSecurityClient::GITHUB_API,
            self::token(),
            self::GITLAB,
            self::token(),
            function (string $method, string $url, array $headers, ?string $body) use ($answers, $status): array {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $this->calls[] = $method . ' ' . $path;
                if ($status !== 200) {
                    return ['status' => $status, 'body' => '{}', 'retry_after' => 0];
                }
                [$code, $answer] = $answers[$path] ?? [404, '{}'];

                return ['status' => $code, 'body' => $answer, 'retry_after' => 0];
            }
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function repositories(): array
    {
        $out = [];
        foreach (CodeSecurity::pageData()['repositories'] as $row) {
            $out[(string) $row['name']] = $row;
        }

        return $out;
    }

    public function testReadStoresTheOpenAlertsOfTheVisibleApplications(): void
    {
        $result = CodeSecurity::read($this->client());
        $this->assertSame('', $result['error']);
        $this->assertSame(2, $result['repositories']);
        $this->assertSame(4, $result['alerts']);
        // L'application de l'entité cachée n'est pas lue ; GitHub en GET seulement.
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('hidden', $call);
            $this->assertTrue(str_starts_with($call, 'GET /repos/') || $call === 'POST /api/graphql', $call);
        }

        $repos = $this->repositories();
        $s = $this->suffix;
        $github = $repos['code-github' . $s];
        $this->assertSame(Rules::PROVIDER_GITHUB, $github['provider']);
        $this->assertSame('example/portal' . $s, $github['repository']);
        $this->assertSame(Rules::SCAN_UNAVAILABLE, $github['code_scan']);
        $this->assertSame(1, $github['counts'][Rules::CATEGORY_DEPENDENCY]);
        $this->assertSame(2, $github['counts'][Rules::CATEGORY_SECRET]);
        $this->assertSame(1, $github['priority']);
        $this->assertSame(Rules::REPO_UNSUPPORTED, $repos['code-other' . $s]['status']);
        $this->assertSame('team/app' . $s, $repos['code-gitlab' . $s]['repository']);
        $this->assertArrayNotHasKey('code-hidden' . $s, $repos);

        $data = CodeSecurity::pageData();
        // Le secret actif d'abord, puis la dépendance hors délai.
        $this->assertSame(Rules::CATEGORY_SECRET, $data['alerts'][0]['category']);
        $this->assertTrue($data['alerts'][0]['priority']);
        $this->assertSame(Rules::CATEGORY_DEPENDENCY, $data['alerts'][1]['category']);
        $this->assertTrue($data['alerts'][1]['overdue']);
        $summary = $data['summary'];
        $this->assertSame(4, $summary['open']);
        $this->assertSame(1, $summary['secrets_priority']);
        // Dépendance critique de 200 jours et secret révoqué mais toujours ouvert après 40 jours.
        $this->assertSame(2, $summary['overdue']);
        $this->assertSame(1, $summary['ages'][Rules::AGE_OLD]);
        $this->assertSame(1, $summary['missing_any']);
        // Les jetons ne sont jamais renvoyés à la page.
        $this->assertStringNotContainsString(self::token(), (string) json_encode($data));
    }

    public function testFailedReadKeepsThePreviousAlerts(): void
    {
        CodeSecurity::read($this->client());
        $result = CodeSecurity::read($this->client(401));
        $this->assertSame(CodeSecurityClient::ERROR_UNAUTHORIZED, $result['error']);
        $this->assertSame(2, $result['failed']);
        $data = CodeSecurity::pageData();
        $this->assertSame(4, $data['total_alerts']);
        $repo = $this->repositories()['code-github' . $this->suffix];
        $this->assertSame(Rules::REPO_ERROR, $repo['status']);
        $this->assertNotSame('', $repo['date_read_label']);
        $this->assertNotSame('', $repo['error_label']);
    }

    public function testTabKriEvidenceAndClear(): void
    {
        CodeSecurity::read($this->client());
        $appliance = new Appliance();
        $this->assertTrue($appliance->getFromDB($this->apps['github']));
        $this->assertNotSame('', (new CodeSecurity())->getTabNameForItem($appliance));
        ob_start();
        CodeSecurity::displayTabContentForItem($appliance);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-code-security-tab', $html);
        $this->assertStringContainsString('Example cloud key ' . $this->suffix, $html);
        $this->assertStringNotContainsString('Cross-site scripting', $html);

        $sources = CodeSecurityFeature::riskIndicatorSources();
        $this->assertSame(1.0, CodeSecurity::secretsNotRevoked([$this->entity]));
        $this->assertSame(2.0, CodeSecurity::overdueAlerts([$this->entity]));
        $this->assertNull(CodeSecurity::secretsNotRevoked([$this->hiddenEntity]));
        $this->assertArrayHasKey('codesecurity_secrets', $sources);

        $evidence = CodeSecurity::evidence('A.8.28');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['summary']['secrets_priority']);
        $this->assertNull(CodeSecurity::evidence('A.5.1'));

        $this->assertTrue(CodeSecurity::clear());
        global $DB;
        $this->assertSame(0, countElementsInTable(CodeSecurityTables::ALERTS, [
            'appliances_id' => [$this->apps['github'], $this->apps['gitlab']],
        ]));
        $this->assertFalse(CodeSecurity::pageData()['readings']);
        $this->assertTrue($DB->tableExists(CodeSecurityTables::REPOSITORIES));
    }

    public function testConfigKeepsTokensSecret(): void
    {
        $token = self::token();
        $saved = CodeSecurityConfig::save('https://github.example.test', $token, false, '', '', false, false);
        $this->assertTrue($saved['saved']);
        $this->assertSame('changed', $saved['changes'][CodeSecurityConfig::GITHUB_TOKEN_KEY]);
        $this->assertTrue(CodeSecurityConfig::hasGithubToken());
        $this->assertSame('https://github.example.test/api/v3', CodeSecurityConfig::githubApi());
        $this->assertSame('github.example.test', CodeSecurityConfig::githubHost());
        $this->assertSame('https://gitlab.com', CodeSecurityConfig::gitlabUrl());
        // Un jeton vide garde celui enregistré ; une adresse refusée n'est pas enregistrée.
        CodeSecurityConfig::save('', '', false, '', '', false, false);
        $this->assertTrue(CodeSecurityConfig::hasGithubToken());
        $this->assertFalse(CodeSecurityConfig::save('ftp://x.example.test', '', false, '', '', false, false)['saved']);
        CodeSecurityConfig::save('', '', true, '', '', false, false);
        $this->assertFalse(CodeSecurityConfig::hasGithubToken());
    }
}

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

use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #858 : règles pures de la sécurité du code (dépôts, gravités, délais, priorités).
 */
final class CodeSecurityRulesTest extends TestCase
{
    public function testGithubRepositoryForms(): void
    {
        $expected = ['provider' => Rules::PROVIDER_GITHUB, 'path' => 'example/portal', 'host' => 'github.com'];
        foreach (
            [
                'https://github.com/example/portal',
                'https://github.com/example/portal.git',
                'https://www.github.com/example/portal/tree/main',
                'git@github.com:example/portal.git',
                'ssh://git@github.com/example/portal.git',
            ] as $url
        ) {
            $this->assertSame($expected, Rules::parseRepository($url, 'github.com', 'gitlab.com'), $url);
        }
        // GitHub Enterprise Server : hôte de la connexion.
        $this->assertSame(
            ['provider' => Rules::PROVIDER_GITHUB, 'path' => 'team/app', 'host' => 'github.example.test'],
            Rules::parseRepository('https://github.example.test/team/app', 'github.example.test', 'gitlab.com')
        );
    }

    public function testGitlabRepositoryForms(): void
    {
        $this->assertSame(
            ['provider' => Rules::PROVIDER_GITLAB, 'path' => 'group/sub/project', 'host' => 'gitlab.example.test'],
            Rules::parseRepository(
                'https://gitlab.example.test/group/sub/project/-/tree/main',
                'github.com',
                'gitlab.example.test'
            )
        );
        $ssh = Rules::parseRepository('git@gitlab.example.test:team/portal.git', 'github.com', 'gitlab.example.test');
        $this->assertSame('team/portal', $ssh['path'] ?? null);
    }

    public function testUnsupportedRepositories(): void
    {
        foreach (
            [
                '', 'https://bitbucket.example.test/team/app', 'https://github.com/only-owner',
                'ftp://github.com/example/portal', 'https://github.com/example/../secret', 'not an address',
                'https://gitlab.example.test/<script>/x',
            ] as $url
        ) {
            $this->assertNull(Rules::parseRepository($url, 'github.com', 'gitlab.example.test'), $url);
        }
    }

    public function testServerUrl(): void
    {
        $this->assertSame('https://gitlab.example.test', Rules::normalizeServerUrl('https://gitlab.example.test/'));
        $this->assertNull(Rules::normalizeServerUrl('http://gitlab.example.test'));
        $this->assertSame('http://gitlab.example.test', Rules::normalizeServerUrl('http://gitlab.example.test', true));
        $this->assertNull(Rules::normalizeServerUrl('https://user:pass@gitlab.example.test'));
        $this->assertNull(Rules::normalizeServerUrl('https://gitlab.example.test/?a=1'));
    }

    public function testSeverities(): void
    {
        $this->assertSame(Rules::CRITICAL, Rules::normalizeSeverity('CRITICAL'));
        $this->assertSame(Rules::HIGH, Rules::normalizeSeverity('error'));
        $this->assertSame(Rules::MEDIUM, Rules::normalizeSeverity('moderate'));
        $this->assertSame(Rules::MEDIUM, Rules::normalizeSeverity('UNKNOWN'));
        $this->assertSame(Rules::LOW, Rules::normalizeSeverity('note'));
        $this->assertSame(Rules::HIGH, Rules::worstSeverity([Rules::LOW, Rules::HIGH, Rules::MEDIUM]));
        $this->assertSame('', Rules::worstSeverity([]));
    }

    public function testDeadlinesAndPriority(): void
    {
        $days = Rules::policyDays(['kev' => 3, 'critical' => 10, 'high' => 'x', 'low' => 0]);
        $this->assertSame(3, $days['kev']);
        $this->assertSame(10, $days['critical']);
        $this->assertSame(Rules::DEFAULT_DAYS['high'], $days['high']);
        $this->assertSame(Rules::DEFAULT_DAYS['low'], $days['low']);
        // Un secret prend le délai le plus court, quelle que soit sa gravité.
        $this->assertSame(3, Rules::deadline(Rules::CATEGORY_SECRET, Rules::LOW, $days));
        $this->assertSame(10, Rules::deadline(Rules::CATEGORY_DEPENDENCY, Rules::CRITICAL, $days));

        $this->assertTrue(Rules::isPriority(Rules::CATEGORY_SECRET, Rules::VALIDITY_ACTIVE));
        $this->assertTrue(Rules::isPriority(Rules::CATEGORY_SECRET, ''));
        $this->assertFalse(Rules::isPriority(Rules::CATEGORY_SECRET, Rules::VALIDITY_INACTIVE));
        $this->assertFalse(Rules::isPriority(Rules::CATEGORY_CODE, ''));
    }

    public function testStateAndSummary(): void
    {
        $days = Rules::policyDays([]);
        $overdue = Rules::state(Rules::CATEGORY_DEPENDENCY, Rules::HIGH, '', '2026-01-01 10:00', '2026-03-02', $days);
        $this->assertSame(60, $overdue['age']);
        $this->assertSame(Rules::AGE_MONTHS, $overdue['bucket']);
        $this->assertSame('2026-01-31', $overdue['due']);
        $this->assertTrue($overdue['overdue']);
        $this->assertSame(-30, $overdue['days_left']);

        $recent = Rules::state(Rules::CATEGORY_SECRET, Rules::CRITICAL, 'unknown', '2026-03-01', '2026-03-02', $days);
        $this->assertFalse($recent['overdue']);
        $this->assertTrue($recent['priority']);
        $this->assertSame(Rules::AGE_RECENT, $recent['bucket']);

        $alerts = [
            ['category' => Rules::CATEGORY_DEPENDENCY, 'severity' => Rules::HIGH] + $overdue,
            ['category' => Rules::CATEGORY_SECRET, 'severity' => Rules::CRITICAL] + $recent,
        ];
        usort($alerts, static fn (array $a, array $b): int => Rules::rank($a) <=> Rules::rank($b));
        $this->assertSame(Rules::CATEGORY_SECRET, $alerts[0]['category']);
        $summary = Rules::summarize($alerts);
        $this->assertSame(2, $summary['open']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(1, $summary['secrets_priority']);
        $this->assertSame(60, $summary['oldest']);
        $this->assertSame(1, $summary['severities'][Rules::CRITICAL]);
        $this->assertSame(1, $summary['categories'][Rules::CATEGORY_DEPENDENCY]);
    }

    public function testWebUrlAndDate(): void
    {
        $this->assertSame('https://github.com/a/b/security/1', Rules::webUrl('https://github.com/a/b/security/1'));
        $this->assertSame('', Rules::webUrl('javascript:alert(1)'));
        $this->assertSame('', Rules::webUrl('https://user:pass@github.com/x'));
        $this->assertSame('2026-02-03 04:05:06', Rules::parseDate('2026-02-03T04:05:06Z'));
        $this->assertSame('', Rules::parseDate('not a date'));
    }
}

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

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneParser;
use GlpiPlugin\Grcmanager\Tests\Mock\MockSentinelOne;
use PHPUnit\Framework\TestCase;

/**
 * Issue #707 : lecture des réponses de SentinelOne (données fictives de la fausse console).
 */
final class SentinelOneParserTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $path, string $token = MockSentinelOne::VIEWER_TOKEN): array
    {
        $response = (new MockSentinelOne())->handle('GET', '/web/api/v2.1/' . $path, ['limit' => '100'], [
            'Authorization' => 'ApiToken ' . $token,
        ]);
        $page = SentinelOneParser::page($response['body']);
        self::assertNotNull($page);

        return $page['items'];
    }

    public function testConsoleUrlRequiresHttpsAndKeepsOnlyTheOrigin(): void
    {
        self::assertSame(
            'https://euce1-100.sentinelone.net',
            SentinelOneParser::normalizeConsoleUrl(' https://EUCE1-100.sentinelone.net/web/api/v2.1/?x=1 ')
        );
        self::assertSame(
            'https://console.example.test:8443',
            SentinelOneParser::normalizeConsoleUrl('https://console.example.test:8443/')
        );
        self::assertNull(SentinelOneParser::normalizeConsoleUrl('http://console.example.test'));
        self::assertSame('http://mock:8099', SentinelOneParser::normalizeConsoleUrl('http://mock:8099', true));
        self::assertNull(SentinelOneParser::normalizeConsoleUrl('https://user:secret@console.example.test'));
        self::assertNull(SentinelOneParser::normalizeConsoleUrl('ftp://console.example.test'));
        self::assertNull(SentinelOneParser::normalizeConsoleUrl('javascript:alert(1)'));
        self::assertNull(SentinelOneParser::normalizeConsoleUrl(''));
    }

    public function testThreatIsMappedToTheEdrImportFields(): void
    {
        $threat = SentinelOneParser::threat($this->items('threats')[0], 'https://console.example.test');

        self::assertNotNull($threat);
        self::assertSame('threat', $threat['kind']);
        self::assertSame('1950000000000000101', $threat['alert_id']);
        self::assertSame('PC-COMPTA-01', $threat['hostname']);
        self::assertSame('1900000000000000001', $threat['agent_id']);
        self::assertSame(str_repeat('a1', 32), $threat['sha256']);
        self::assertSame('T1204.002, T1547.001', $threat['technique']);
        self::assertSame('critical', EdrCsvImport::severity($threat['severity']));
        self::assertSame(
            'https://console.example.test/incidents/threats/1950000000000000101/overview',
            $threat['console_url']
        );
        self::assertNotNull(EdrCsvImport::parseDate($threat['detected_at']));
    }

    public function testAlertIsMappedToTheEdrImportFields(): void
    {
        $alert = SentinelOneParser::alert($this->items('cloud-detection/alerts')[0]);

        self::assertNotNull($alert);
        self::assertSame('alert', $alert['kind']);
        self::assertSame('1960000000000000201', $alert['alert_id']);
        self::assertSame('critical', EdrCsvImport::severity($alert['severity']));
        self::assertSame('T1003.001', $alert['technique']);
        self::assertStringContainsString('lsass', $alert['command_line']);
        self::assertSame('', $alert['console_url']);
    }

    public function testObjectsWithoutIdentifierOrWithInvalidValuesAreDropped(): void
    {
        self::assertNull(SentinelOneParser::threat(['threatInfo' => ['threatName' => 'x']]));
        self::assertNull(SentinelOneParser::alert(['ruleInfo' => ['name' => 'x']]));
        self::assertNull(SentinelOneParser::agent(['computerName' => 'x']));
        self::assertNull(SentinelOneParser::vulnerability(['cveId' => 'not-a-cve']));

        // Valeurs non scalaires ou empreintes mal formées : ignorées, sans erreur.
        $threat = SentinelOneParser::threat(['id' => '1', 'threatInfo' => ['sha256' => 'zz', 'threatName' => ['x']]]);
        self::assertNotNull($threat);
        self::assertSame('', $threat['sha256']);
        self::assertSame('', $threat['title']);
    }

    public function testAgentsAndVulnerabilities(): void
    {
        $agents = array_map([SentinelOneParser::class, 'agent'], $this->items('agents'));
        self::assertCount(4, array_filter($agents));
        self::assertSame('SN-FICTIF-RH-01', $agents[1]['serial'] ?? null);
        self::assertFalse($agents[3]['is_active'] ?? true);

        $vulnerabilities = array_values(array_filter(array_map(
            [SentinelOneParser::class, 'vulnerability'],
            $this->items('application-management/risks')
        )));
        self::assertCount(5, $vulnerabilities);
        self::assertSame('CVE-2025-29927', $vulnerabilities[0]['cve']);
        self::assertSame(9.1, $vulnerabilities[0]['score']);
        self::assertSame('Next.js', $vulnerabilities[0]['application']);
    }

    public function testImportRowFollowsTheCsvImportFields(): void
    {
        $threat = SentinelOneParser::threat($this->items('threats')[0]);
        self::assertNotNull($threat);
        $import = SentinelOneParser::toImportRow($threat);

        self::assertSame(array_keys(EdrCsvImport::FIELDS), $import['headers']);
        $groups = EdrCsvImport::group([$import['row']], $import['mapping'], 'detection');
        self::assertCount(1, $groups);
        self::assertSame(['1950000000000000101'], $groups[0]['external_ids']);
        self::assertSame(['PC-COMPTA-01'], $groups[0]['hosts']);
        self::assertContains('T1547.001', $groups[0]['techniques']);
        self::assertContains(str_repeat('a1', 32), $groups[0]['iocs']);
    }

    public function testPageReadsCursorAndTotal(): void
    {
        self::assertNull(SentinelOneParser::page('not json'));
        self::assertNull(SentinelOneParser::page('{"errors": []}'));
        $page = SentinelOneParser::page(
            '{"data": [{"id": "1"}, "x"], "pagination": {"nextCursor": "abc", "totalItems": 7}}'
        );
        self::assertNotNull($page);
        self::assertCount(1, $page['items']);
        self::assertSame('abc', $page['next_cursor']);
        self::assertSame(7, $page['total']);
    }
}

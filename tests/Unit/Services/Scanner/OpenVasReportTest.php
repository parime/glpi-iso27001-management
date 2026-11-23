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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Scanner;

use GlpiPlugin\Grcmanager\Services\Scanner\OpenVasReport;
use GlpiPlugin\Grcmanager\Services\Scanner\ScannerReports as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #543 : lecture du rapport XML de Greenbone / OpenVAS (exemple livré, structure du rapport
 * officiel publié par Greenbone), règles communes, fichiers piégés.
 */
final class OpenVasReportTest extends TestCase
{
    private static function example(): string
    {
        return dirname(__DIR__, 4) . '/resources/openvas-example.xml';
    }

    public function testExampleIsRecognisedAndRead(): void
    {
        $parsed = R::parseFile(self::example());

        $this->assertSame('', $parsed['error']);
        $this->assertSame('openvas', $parsed['scanner']);
        $this->assertSame('6f0a8a8e-2f4b-4b1e-9a51-0c5d7e3a1b20', $parsed['report']['uuid']);
        $this->assertSame('Serveurs exemple.test', $parsed['report']['task']);
        $this->assertNotSame('', $parsed['report']['scan_start']);
        // Quatre constats, le résultat « Log » (0.0) écarté ; l'erreur de test n'est pas un résultat.
        $this->assertCount(4, $parsed['findings']);
        $this->assertSame(1, $parsed['skipped']);
        $this->assertFalse($parsed['truncated']);
        // Les trois hôtes analysés, même celui sans nom.
        $this->assertSame(
            ['192.0.2.10' => 'srv-web01.exemple.test', '192.0.2.20' => 'pc-compta-02', '192.0.2.99' => ''],
            $parsed['hosts']
        );
    }

    public function testFindingFields(): void
    {
        $finding = R::parseFile(self::example())['findings'][0];

        $this->assertSame('192.0.2.10', $finding['host']);
        $this->assertSame('srv-web01.exemple.test', $finding['hostname']);
        $this->assertSame('8083/tcp', $finding['port']);
        $this->assertSame('1.3.6.1.4.1.25623.1.0.107305', $finding['test_id']);
        $this->assertSame('Sensitive File Disclosure (HTTP)', $finding['name']);
        $this->assertSame('Web application abuses', $finding['family']);
        $this->assertSame(5.0, $finding['severity']);
        $this->assertSame(70, $finding['qod']);
        $this->assertSame(['CVE-2017-16894'], $finding['cves']);
        $this->assertSame('Mitigation', $finding['solution_type']);
        $this->assertStringContainsString('Restrict access', $finding['solution']);
    }

    public function testEmptyCveReferencesAreIgnored(): void
    {
        $findings = R::parseFile(self::example())['findings'];

        $this->assertSame([], $findings[1]['cves']);
        $this->assertSame(['CVE-2011-3389', 'CVE-2015-0204', 'CVE-2023-41928'], $findings[2]['cves']);
    }

    public function testGetReportsResponseAndOldCveList(): void
    {
        $xml = '<get_reports_response status="200" status_text="OK"><report id="r1"><report id="r1">'
            . '<gmp><version>8.0</version></gmp><scan_start>2026-01-05T10:00:00Z</scan_start><results>'
            . '<result id="x"><name>Old test</name><host>10.0.0.5</host><port>80/tcp</port>'
            . '<nvt oid="1.3.6.1.4.1.25623.1.0.1"><name>Old test</name><cvss_base>9.8</cvss_base>'
            . '<cve>CVE-2020-0001, CVE-2020-0002</cve></nvt><threat>High</threat><severity>9.8</severity>'
            . '</result></results></report></report></get_reports_response>';
        $parsed = OpenVasReport::parseString($xml);

        $this->assertSame('', $parsed['error']);
        $this->assertSame('r1', $parsed['report']['uuid']);
        $this->assertSame(['CVE-2020-0001', 'CVE-2020-0002'], $parsed['findings'][0]['cves']);
        $this->assertSame(R::SEVERITY_CRITICAL, R::severityLevel($parsed['findings'][0]['severity']));
    }

    public function testDoctypeAndEntitiesAreRefused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE report [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<report id="a"><gmp/><results><result><name>&x;</name></result></results></report>';

        $this->assertSame(R::ERROR_DOCTYPE, OpenVasReport::parseString($xml)['error']);
        $path = tempnam(sys_get_temp_dir(), 'grc');
        file_put_contents($path, $xml);
        try {
            $this->assertSame(R::ERROR_DOCTYPE, R::parseFile($path)['error']);
        } finally {
            unlink($path);
        }
    }

    public function testOtherFilesAreRejected(): void
    {
        $this->assertSame(R::ERROR_INVALID, OpenVasReport::parseString('<Benchmark><gmp/></Benchmark>')['error']);
        $this->assertSame(R::ERROR_INVALID, OpenVasReport::parseString('not xml at all')['error']);
        $path = tempnam(sys_get_temp_dir(), 'grc');
        file_put_contents($path, '<NessusClientData_v2><Report/></NessusClientData_v2>');
        try {
            $this->assertSame(R::ERROR_UNKNOWN_FORMAT, R::parseFile($path)['error']);
        } finally {
            unlink($path);
        }
    }

    public function testMarkupInTextIsKeptAsText(): void
    {
        $xml = '<report id="a"><report id="a"><gmp/><results><result><name>&lt;script&gt;alert(1)&lt;/script&gt;</name>'
            . '<host>192.0.2.1</host><port>80/tcp</port><nvt oid="1"/><severity>5.0</severity></result>'
            . '</results></report></report>';
        $finding = OpenVasReport::parseString($xml)['findings'][0];

        // Gardé tel quel : l'échappement est fait à l'affichage (Twig).
        $this->assertSame('<script>alert(1)</script>', $finding['name']);
    }

    public function testSeverityLevels(): void
    {
        $this->assertSame(R::SEVERITY_CRITICAL, R::severityLevel(9.0));
        $this->assertSame(R::SEVERITY_HIGH, R::severityLevel(7.0));
        $this->assertSame(R::SEVERITY_MEDIUM, R::severityLevel(4.0));
        $this->assertSame(R::SEVERITY_LOW, R::severityLevel(0.1));
        $this->assertNull(R::severityLevel(0.0));
        $this->assertNull(R::severityLevel(-1.0));
    }

    public function testFindingKeyIsStable(): void
    {
        $this->assertSame(
            R::findingKey('openvas', '192.0.2.10', '80/tcp', '1.2'),
            R::findingKey('openvas', ' 192.0.2.10. ', '80/TCP', '1.2')
        );
        $this->assertNotSame(
            R::findingKey('openvas', '192.0.2.10', '80/tcp', '1.2'),
            R::findingKey('openvas', '192.0.2.10', '443/tcp', '1.2')
        );
    }

    public function testCountBySeverity(): void
    {
        $counts = R::countBySeverity(R::parseFile(self::example())['findings']);

        $this->assertSame(0, $counts[R::SEVERITY_CRITICAL]);
        $this->assertSame(3, $counts[R::SEVERITY_MEDIUM]);
        $this->assertSame(1, $counts[R::SEVERITY_LOW]);
        $this->assertSame(4, $counts['total']);
    }
}

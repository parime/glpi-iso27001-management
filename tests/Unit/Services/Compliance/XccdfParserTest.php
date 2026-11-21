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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Compliance\XccdfParser as P;
use PHPUnit\Framework\TestCase;

/**
 * Issue #781 : lecture durcie des résultats XCCDF et ARF, sur des fichiers fictifs et piégés.
 */
final class XccdfParserTest extends TestCase
{
    private const ARF = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<arf:asset-report-collection xmlns:arf="http://scap.nist.gov/schema/asset-reporting-format/1.1"
    xmlns:ai="http://scap.nist.gov/schema/asset-identification/1.1"
    xmlns:ds="http://scap.nist.gov/schema/scap/source/1.2">
  <arf:report-requests><arf:report-request id="req"><arf:content>
    <ds:data-stream-collection><ds:component id="c">
      <Benchmark xmlns="http://checklists.nist.gov/xccdf/1.2" id="xccdf_demo_benchmark_arf">
        <title>Demo benchmark</title>
        <Profile id="xccdf_demo_profile_cis"><title>Demo level 1</title></Profile>
        <Group id="g"><Rule id="xccdf_demo_rule_a" severity="high"><title>Rule A</title></Rule>
        <Rule id="xccdf_demo_rule_b" severity="low"><title>Rule B</title></Rule></Group>
      </Benchmark>
    </ds:component></ds:data-stream-collection>
  </arf:content></arf:report-request></arf:report-requests>
  <arf:assets><arf:asset id="asset0"><ai:computing-device>
    <ai:fqdn>pc-demo-02.example.org</ai:fqdn><ai:hostname>pc-demo-02</ai:hostname>
  </ai:computing-device></arf:asset></arf:assets>
  <arf:reports><arf:report id="r"><arf:content>
    <TestResult xmlns="http://checklists.nist.gov/xccdf/1.2" id="tr" start-time="2026-04-01T10:00:00Z">
      <benchmark href="#xccdf_demo_benchmark_arf"/>
      <profile idref="xccdf_demo_profile_cis"/>
      <rule-result idref="xccdf_demo_rule_a"><result>fail</result></rule-result>
      <rule-result idref="xccdf_demo_rule_b" severity="low"><result>pass</result></rule-result>
      <rule-result idref="xccdf_demo_rule_c"><result>notchecked</result></rule-result>
      <score system="urn:xccdf:scoring:default" maximum="100">50</score>
    </TestResult>
  </arf:content></arf:report></arf:reports>
</arf:asset-report-collection>
XML;

    public function testStandaloneResultsOfTheExampleFile(): void
    {
        $result = P::parseFile(dirname(__DIR__, 4) . '/resources/openscap-results-example.xml');

        $this->assertSame('', $result['error']);
        $this->assertCount(1, $result['reports']);
        $report = $result['reports'][0];
        $this->assertSame('srv-demo-01', $report['hostname']);
        $this->assertSame('srv-demo-01.example.org', $report['fqdn']);
        $this->assertSame('xccdf_org.example.content_profile_server', $report['profile_id']);
        $this->assertSame('Example server baseline', $report['profile_title']);
        $this->assertSame('Example hardening guide for Linux servers', $report['benchmark_title']);
        $this->assertSame(1, $report['counts']['pass']);
        $this->assertSame(1, $report['counts']['fail']);
        $this->assertSame(1, $report['counts']['error']);
        $this->assertSame(1, $report['counts']['notapplicable']);
        $this->assertSame(40.0, $report['score']);
        $this->assertSame(1, $report['high_failed']);
        $this->assertSame(
            ['Disable SSH root login', 'Enable the audit service'],
            array_column($report['failed'], 'title')
        );
        $this->assertNotSame('', $report['start_time']);
    }

    public function testArfReportTakesTheAssetNameAndRuleSeverityFromTheBenchmark(): void
    {
        $result = P::parseString(self::ARF);

        $this->assertSame('', $result['error']);
        $report = $result['reports'][0];
        $this->assertSame('pc-demo-02', $report['hostname']);
        $this->assertSame('pc-demo-02.example.org', $report['fqdn']);
        $this->assertSame('xccdf_demo_benchmark_arf', $report['benchmark_id']);
        $this->assertSame('Demo level 1', $report['profile_title']);
        $this->assertSame('high', $report['failed'][0]['severity'], 'Severity read on the rule definition.');
        $this->assertSame('Rule A', $report['failed'][0]['title']);
        $this->assertSame(1, $report['counts']['notchecked']);
    }

    public function testExternalEntityIsRefusedAndNeverRead(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'grc781');
        file_put_contents($secret, 'PhpUnitDemo secret value');
        $xml = '<?xml version="1.0"?><!DOCTYPE Benchmark [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<Benchmark xmlns="http://checklists.nist.gov/xccdf/1.2"><TestResult id="t">'
            . '<target>&xxe;</target></TestResult></Benchmark>';

        $result = P::parseString($xml);
        @unlink($secret);

        $this->assertSame(P::ERROR_DOCTYPE, $result['error']);
        $this->assertSame([], $result['reports']);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function testEntityExpansionBombIsRefused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE b [<!ENTITY a "aaaaaaaaaa">'
            . '<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]>'
            . '<Benchmark><TestResult id="t"><target>&c;</target></TestResult></Benchmark>';

        $this->assertSame(P::ERROR_DOCTYPE, P::parseString($xml)['error']);
    }

    public function testTrappedFileOnDiskIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'grc781');
        file_put_contents($path, '<?xml version="1.0"?><!DOCTYPE x SYSTEM "http://192.0.2.1/evil.dtd"><x/>');

        $result = P::parseFile($path);
        @unlink($path);

        $this->assertSame(P::ERROR_DOCTYPE, $result['error']);
    }

    public function testMalformedTruncatedOrEmptyFiles(): void
    {
        $truncated = '<Benchmark><TestResult id="t"><target>x</target>';
        $this->assertSame(P::ERROR_INVALID, P::parseString($truncated)['error']);
        $this->assertSame(P::ERROR_INVALID, P::parseString('not xml at all')['error']);
        $this->assertSame(P::ERROR_NO_RESULT, P::parseString('<Benchmark id="b"/>')['error']);
        $this->assertSame(P::ERROR_UNREADABLE, P::parseString('')['error']);
        $this->assertSame(P::ERROR_UNREADABLE, P::parseFile('/nonexistent/grc781.xml')['error']);
    }

    public function testTextIsTrimmedAndControlCharactersRemoved(): void
    {
        $long = str_repeat('x', 400);
        $xml = '<Benchmark><TestResult id="t"><target>  ' . $long . "  </target>"
            . '<rule-result idref="r1"><result>fail</result></rule-result></TestResult>'
            . '<Rule id="r1"><title>Title&#9;with&#10;spaces</title></Rule></Benchmark>';

        $report = P::parseString($xml)['reports'][0];

        $this->assertSame(255, mb_strlen($report['target']));
        $this->assertSame('Title with spaces', $report['failed'][0]['title']);
    }
}

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

use GlpiPlugin\Grcmanager\ConfigurationCompliance;
use GlpiPlugin\Grcmanager\Install\ConfigScanTables;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Compliance\XccdfParser;

/**
 * Issue #781 : import de résultats OpenSCAP fictifs, rapprochement avec les ordinateurs d'une entité
 * de test (seule entité active), ordinateur homonyme d'une entité cachée, preuve, KRI et ticket.
 */
final class ConfigurationComplianceTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    /** @var array<string, int> */
    private array $pc = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Config ' . $this->suffix);
        $hidden = $this->createTestEntity(0, 'PhpUnitDemo Config hidden ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        $computer = fn (string $name, int $entity): int => (int) (new \Computer())->add([
            'name' => $name, 'entities_id' => $entity,
        ]);
        $this->pc = [
            'web'    => $computer('phpunitdemo-web-' . $this->suffix, $this->entity),
            'db'     => $computer('phpunitdemo-db-' . $this->suffix, $this->entity),
            'hidden' => $computer('phpunitdemo-hidden-' . $this->suffix, $hidden),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        parent::tearDown();
    }

    private function scanResult(string $host, string $date, string $sshResult, string $auditResult): string
    {
        return '<TestResult id="xccdf_demo_testresult_' . $host . '" start-time="' . $date . '"'
            . ' test-system="cpe:/a:redhat:openscap:1.3.10">'
            . '<benchmark id="xccdf_demo_benchmark"/><profile idref="xccdf_demo_profile_server"/>'
            . '<target>' . $host . '.example.org</target>'
            . '<target-facts><fact name="urn:xccdf:fact:asset:identifier:host_name" type="string">' . $host
            . '</fact></target-facts>'
            . '<rule-result idref="xccdf_demo_rule_ssh" severity="high"><result>' . $sshResult
            . '</result></rule-result>'
            . '<rule-result idref="xccdf_demo_rule_audit" severity="medium"><result>' . $auditResult
            . '</result></rule-result>'
            . '<rule-result idref="xccdf_demo_rule_banner" severity="low"><result>pass</result></rule-result>'
            . '</TestResult>';
    }

    /**
     * @param list<string> $results
     */
    private function file(array $results): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'grc781');
        $this->files[] = $path;
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Benchmark xmlns="http://checklists.nist.gov/xccdf/1.2" id="xccdf_demo_benchmark">'
            . '<title>PhpUnitDemo guide</title>'
            . '<Profile id="xccdf_demo_profile_server"><title>PhpUnitDemo server profile</title></Profile>'
            . '<Rule id="xccdf_demo_rule_ssh" severity="high"><title>PhpUnitDemo disable root login</title></Rule>'
            . '<Rule id="xccdf_demo_rule_audit" severity="medium"><title>PhpUnitDemo enable audit</title></Rule>'
            . '<Rule id="xccdf_demo_rule_banner" severity="low"><title>PhpUnitDemo banner</title></Rule>'
            . implode('', $results)
            . '</Benchmark>');

        return $path;
    }

    private function host(string $key): string
    {
        return 'phpunitdemo-' . $key . '-' . $this->suffix;
    }

    public function testImportMatchesVisibleComputersOnly(): void
    {
        $stats = ConfigurationCompliance::importFile($this->file([
            $this->scanResult($this->host('web'), '2026-01-10T08:00:00Z', 'fail', 'pass'),
            $this->scanResult($this->host('hidden'), '2026-01-10T08:00:00Z', 'fail', 'fail'),
            $this->scanResult($this->host('unknown'), '2026-01-10T08:00:00Z', 'pass', 'pass'),
        ]), 'results.xml');

        $this->assertSame('', $stats['error']);
        $this->assertSame(3, $stats['reports']);
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(2, $stats['unmatched'], 'The computer of the hidden entity is never matched.');

        $data = ConfigurationCompliance::pageData();
        $this->assertSame([$this->pc['web']], array_column($data['rows'], 'computers_id'));
        $this->assertSame(66, $data['rows'][0]['rate']);
        $this->assertSame(1, (int) $data['rows'][0]['high_fail_count']);
        $this->assertSame('PhpUnitDemo server profile', $data['rows'][0]['profile_title']);
        $this->assertCount(2, $data['unmatched']);
        foreach ($data['unmatched'] as $row) {
            $this->assertSame($this->entity, (int) $row['entities_id'], 'Unmatched results stay in the active entity.');
            $this->assertSame('', $row['computer_name']);
        }
        $this->assertSame('xccdf_demo_rule_ssh', $data['top_rules'][0]['rule_id']);
        $this->assertSame(2, $data['top_rules'][0]['count']);

        // Même fichier importé une seconde fois : rien n'est doublé.
        $again = ConfigurationCompliance::importFile($this->file([
            $this->scanResult($this->host('web'), '2026-01-10T08:00:00Z', 'fail', 'pass'),
        ]), 'results.xml');
        $this->assertSame(0, $again['reports']);
        $this->assertSame(1, $again['duplicates']);
    }

    public function testTrendHistoryTicketManualMatchAndDeletion(): void
    {
        ConfigurationCompliance::importFile($this->file([
            $this->scanResult($this->host('web'), '2026-01-10T08:00:00Z', 'fail', 'fail'),
            $this->scanResult('phpunitdemo-alias-' . $this->suffix, '2026-01-10T08:00:00Z', 'fail', 'pass'),
        ]), 'january.xml');
        ConfigurationCompliance::importFile($this->file([
            $this->scanResult($this->host('web'), '2026-02-10T08:00:00Z', 'fail', 'pass'),
        ]), 'february.xml');

        $row = ConfigurationCompliance::pageData()['rows'][0];
        $this->assertSame(66, $row['rate']);
        $this->assertSame(33, $row['trend'], 'From 33 % to 66 %.');
        $detail = ConfigurationCompliance::reportData($row['id']);
        $this->assertNotNull($detail);
        $this->assertCount(2, $detail['history']);
        $this->assertSame(['PhpUnitDemo disable root login'], array_column($detail['failures'], 'title'));

        $ticketId = ConfigurationCompliance::createTicket($row['id']);
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame($ticketId, ConfigurationCompliance::createTicket($row['id']), 'One ticket per scan.');
        $this->assertSame(1, countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticketId, 'itemtype' => \Computer::class, 'items_id' => $this->pc['web'],
        ]));

        $alias = ConfigurationCompliance::pageData()['unmatched'][0];
        $this->assertFalse(ConfigurationCompliance::linkComputer($alias['id'], $this->pc['hidden']));
        $this->assertTrue(ConfigurationCompliance::linkComputer($alias['id'], $this->pc['db']));
        $this->assertSame([], ConfigurationCompliance::pageData()['unmatched']);
        $this->assertSame($this->pc['db'], ConfigurationCompliance::getReport($alias['id'])['computers_id'] ?? 0);

        $this->assertTrue(ConfigurationCompliance::deleteReport($alias['id']));
        $this->assertNull(ConfigurationCompliance::getReport($alias['id']));
        $this->assertSame(0, countElementsInTable(ConfigScanTables::FAILURES, [
            ConfigScanTables::FK => $alias['id'],
        ]));
    }

    public function testTrappedFileImportsNothing(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'grc781');
        $this->files[] = $path;
        file_put_contents($path, '<?xml version="1.0"?><!DOCTYPE Benchmark [<!ENTITY x SYSTEM "file:///etc/hostname">]>'
            . '<Benchmark><TestResult id="t"><target>&x;</target></TestResult></Benchmark>');

        $stats = ConfigurationCompliance::importFile($path, 'trap.xml');

        $this->assertSame(XccdfParser::ERROR_DOCTYPE, $stats['error']);
        $this->assertSame(0, $stats['reports']);
        $this->assertSame([], ConfigurationCompliance::reports());
    }

    public function testEvidenceKriAndRights(): void
    {
        $empty = ConfigurationCompliance::evidence('A.8.9');
        $this->assertNotNull($empty, 'The source is shown, with a link to import, before any scan.');
        $this->assertSame('openscap', $empty['sources'][0]['key']);
        $this->assertNull(ConfigurationCompliance::evidence('A.5.1'));

        ConfigurationCompliance::importFile($this->file([
            $this->scanResult($this->host('web'), '2026-01-10T08:00:00Z', 'fail', 'pass'),
            $this->scanResult($this->host('db'), '2026-01-10T08:00:00Z', 'pass', 'pass'),
        ]), 'results.xml');
        $evidence = ConfigurationCompliance::evidence('A.8.9');
        $this->assertNotNull($evidence);
        $this->assertCount(4, $evidence['sources'][0]['badges']);
        $this->assertArrayHasKey('config_high_failures', RiskIndicator::getSources());
        $this->assertSame(50.0, ConfigurationCompliance::highFailureRate([$this->entity]));
        $this->assertNull(ConfigurationCompliance::highFailureRate([]));

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        try {
            $this->assertFalse(ConfigurationCompliance::canView());
            $this->assertNull(ConfigurationCompliance::evidence('A.8.9'));
            $this->assertSame([], ConfigurationCompliance::reports());
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }

        $plugin = $_SESSION['glpiactiveprofile']['plugin_grcmanager'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertFalse(ConfigurationCompliance::canManage());
            $this->assertSame('rights', ConfigurationCompliance::importFile($this->file([]), 'x.xml')['error']);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $plugin;
        }
    }
}

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

use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\Install\LogCoverageTables;
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules as Rules;

/**
 * Issue #853 : couverture de la journalisation, sur des actifs et des hôtes émetteurs fictifs
 * d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class LogCoverageTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    /** @var array<string, int> */
    private array $pc = [];

    private int $firewall;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        LogCoverage::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Journaux ' . $this->suffix);
        $hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        $serverType = (int) (new \ComputerType())->import(['name' => 'Server']);
        $desktopType = (int) (new \ComputerType())->import(['name' => 'PhpUnitDemo Desktop ' . $this->suffix]);
        $computer = fn (string $name, int $type, int $entity = 0): int => (int) (new \Computer())->add([
            'name'             => $this->host($name),
            'entities_id'      => $entity > 0 ? $entity : $this->entity,
            'computertypes_id' => $type,
        ]);
        $this->pc = [
            'server_ok'    => $computer('srv-ok', $serverType),
            'server_stale' => $computer('srv-stale', $serverType),
            'critical'     => $computer('poste-paie', $desktopType),
            'plain'        => $computer('poste', $desktopType),
            'hidden'       => $computer('srv-cache', $serverType, $hidden),
        ];
        $this->firewall = (int) (new \NetworkEquipment())->add([
            'name' => $this->host('pare-feu'), 'entities_id' => $this->entity,
        ]);
        foreach ($this->pc + ['firewall' => $this->firewall] as $id) {
            $this->assertGreaterThan(0, $id);
        }
        global $DB;
        $DB->insert('glpi_plugin_grcmanager_assetclassifications', [
            'itemtype' => \Computer::class, 'items_id' => $this->pc['critical'], 'integrity' => 'high',
        ]);
        $DB->insert('glpi_ipaddresses', [
            'entities_id'  => $this->entity,
            'items_id'     => 0,
            'itemtype'     => 'NetworkName',
            'version'      => 4,
            'name'         => '192.0.2.45',
            'is_deleted'   => 0,
            'mainitems_id' => $this->pc['critical'],
            'mainitemtype' => \Computer::class,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        LogCoverage::reset();
        parent::tearDown();
    }

    private function host(string $name): string
    {
        return 'phpunitdemo-' . $name . '-' . $this->suffix;
    }

    private function csv(): string
    {
        $recent = time() - 3600;
        $old = time() - 30 * 86400;

        return "host,ip,lastTime\n"
            . $this->host('srv-ok') . ".exemple.test,," . $recent . "\n"
            . $this->host('srv-stale') . ",," . $old . "\n"
            . "inconnu-" . $this->suffix . ",198.51.100.7," . $recent . "\n"
            . ",192.0.2.45," . $recent . "\n"
            . $this->host('srv-cache') . ",," . $recent . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $itemtype = \Computer::class): array
    {
        foreach (LogCoverage::pageData()['rows'] as $row) {
            if ($row['id'] === $id && $row['itemtype'] === $itemtype) {
                return $row;
            }
        }
        $this->fail('Actif absent de la page.');
    }

    public function testImportMatchesAssetsAndFlagsGaps(): void
    {
        $result = LogCoverage::import('splunk', $this->csv());
        $this->assertSame([], $result['errors']);
        $this->assertSame(5, $result['imported']);
        $this->assertSame(3, $result['matched'], 'Nom court, nom exact, adresse IP.');
        $this->assertSame(2, $result['unmatched'], 'Hôte inconnu et ordinateur hors entités.');

        $this->assertSame(Rules::STATUS_OK, $this->row($this->pc['server_ok'])['status']);
        $this->assertSame(['Splunk'], $this->row($this->pc['server_ok'])['platforms']);
        $this->assertSame(Rules::STATUS_STALE, $this->row($this->pc['server_stale'])['status']);
        $this->assertSame(30, $this->row($this->pc['server_stale'])['age']);
        $critical = $this->row($this->pc['critical']);
        $this->assertSame(Rules::STATUS_OK, $critical['status'], 'Rapproché par son adresse IP.');
        $this->assertTrue($critical['critical']);
        $this->assertSame(
            Rules::STATUS_SILENT,
            $this->row($this->firewall, \NetworkEquipment::class)['status'],
            'Matériel réseau qui n\'envoie rien.'
        );

        $data = LogCoverage::pageData();
        $ids = array_column($data['rows'], 'id');
        $this->assertNotContains($this->pc['plain'], $ids, 'Poste ordinaire hors du périmètre par défaut.');
        $this->assertNotContains($this->pc['hidden'], $ids, 'Entité hors de la session.');
        $this->assertSame(Rules::STATUS_SILENT, $data['rows'][0]['status'], 'Écarts d\'abord.');
        $this->assertSame(4, $data['summary']['concerned']['total']);
        $this->assertSame(50, $data['summary']['concerned']['rate']);
        $this->assertSame(1, $data['summary']['critical']['total']);
        $this->assertSame(0, $data['summary']['critical']['uncovered']);
        $unmatched = array_column($data['unmatched'], 'host');
        $this->assertContains('inconnu-' . $this->suffix, $unmatched);
        $this->assertSame(['splunk'], array_column($data['platforms'], 'platform'));
        $this->assertSame(5, $data['platforms'][0]['hosts']);

        // Nouvel import de la même plateforme : remplace le précédent.
        LogCoverage::import('splunk', "host\n" . $this->host('pare-feu') . "\n");
        $this->assertSame(
            1,
            countElementsInTable(LogCoverageTables::HOSTS, ['entities_id' => $this->entity, 'platform' => 'splunk'])
        );
        $this->assertSame(Rules::STATUS_OK, $this->row($this->firewall, \NetworkEquipment::class)['status']);
        $this->assertSame(Rules::STATUS_SILENT, $this->row($this->pc['server_ok'])['status']);

        $this->assertTrue(LogCoverage::clear('splunk'));
        $this->assertSame(0, countElementsInTable(LogCoverageTables::HOSTS, ['entities_id' => $this->entity]));
        $this->assertSame(['missing_host'], LogCoverage::import('graylog', "count\n3\n")['errors']);
    }

    public function testScopeSettings(): void
    {
        LogCoverage::import('elastic', $this->csv());
        $saved = LogCoverage::setSettings(['scope' => 'critical', 'policy_days' => '400', 'stale_days' => '45']);
        $this->assertSame(['scope' => 'critical', 'policy_days' => 400, 'stale_days' => 45], $saved);
        $this->assertSame($saved, LogCoverage::getSettings());
        $ids = array_column(LogCoverage::pageData()['rows'], 'id');
        $this->assertSame([$this->pc['critical']], $ids, 'Actifs critiques seulement.');

        LogCoverage::setSettings(['scope' => 'all', 'stale_days' => '45']);
        $this->assertSame(Rules::STATUS_OK, $this->row($this->pc['server_stale'])['status'], 'Seuil de 45 jours.');
        $this->assertSame(Rules::STATUS_SILENT, $this->row($this->pc['plain'])['status']);
    }

    public function testRetentionAndClockDeclarations(): void
    {
        LogCoverage::import('splunk', $this->csv());
        $declarations = LogCoverage::declarations(LogCoverage::pageData()['platforms']);
        $this->assertCount(1, $declarations);
        $this->assertFalse($declarations[0]['declared']);
        $this->assertSame(Rules::RETENTION_UNDECLARED, $declarations[0]['retention']);

        $this->assertTrue(LogCoverage::declarePlatform([
            'platform' => 'splunk', 'retention_days' => '200', 'is_ntp_declared' => '1',
            'ntp_server' => 'ntp.exemple.test', 'comment' => 'Paramètre de l\'index main',
        ]));
        $splunk = LogCoverage::declarations()[0];
        $this->assertSame(Rules::RETENTION_OK, $splunk['retention'], '200 jours pour une politique de 180.');
        $this->assertTrue($splunk['ntp']);
        $this->assertSame('ntp.exemple.test', $splunk['ntp_server']);

        $obligation = (int) (new ComplianceObligation())->add([
            'title' => 'PhpUnitDemo Obligation de conservation ' . $this->suffix, 'entities_id' => $this->entity,
        ]);
        $this->assertGreaterThan(0, $obligation);
        $this->assertTrue(LogCoverage::declarePlatform([
            'platform' => 'splunk', 'retention_days' => '200', 'obligation_days' => '365',
            'plugin_grcmanager_complianceobligations_id' => (string) $obligation,
        ]));
        $splunk = LogCoverage::declarations()[0];
        $this->assertSame(365, $splunk['required_days'], 'L\'obligation est plus exigeante que la politique.');
        $this->assertSame(Rules::RETENTION_SHORT, $splunk['retention']);
        $this->assertFalse($splunk['ntp'], 'Case décochée.');
        $this->assertStringContainsString('Obligation de conservation', $splunk['obligation']);
        $this->assertSame(
            1,
            countElementsInTable(LogCoverageTables::PLATFORMS, ['entities_id' => $this->entity, 'platform' => 'splunk']),
            'Une déclaration par plateforme et par entité.'
        );

        $this->assertTrue(LogCoverage::declarePlatform([
            'platform' => 'graylog', 'retention_days' => '30',
            'plugin_grcmanager_complianceobligations_id' => '999999999', 'obligation_days' => '9000',
        ]));
        $graylog = array_values(array_filter(
            LogCoverage::declarations(),
            static fn (array $row): bool => $row['platform'] === 'graylog'
        ))[0];
        $this->assertSame(0, $graylog['obligation_id'], 'Obligation inexistante ignorée.');
        $this->assertSame(180, $graylog['required_days']);
        $summary = LogCoverage::summary();
        $this->assertSame(2, $summary['retention_gaps']);
        $this->assertSame(2, $summary['ntp_missing']);
    }

    public function testEvidenceKriAndRights(): void
    {
        LogCoverage::import('splunk', $this->csv());
        $evidence = LogCoverage::evidence('A.8.15');
        $this->assertNotNull($evidence);
        $this->assertSame(4, $evidence['concerned']);
        $this->assertSame(1, $evidence['silent']);
        $this->assertSame(1, $evidence['stale']);
        $this->assertNotNull(LogCoverage::evidence('A.8.16'));
        $this->assertNotNull(LogCoverage::evidence('A.8.17'));
        $this->assertNull(LogCoverage::evidence('A.5.1'));
        $this->assertArrayHasKey('critical_assets_without_logging', RiskIndicator::getSources());
        $this->assertSame(0.0, LogCoverage::criticalWithoutLogging([$this->entity]));
        $this->assertNull(LogCoverage::criticalWithoutLogging([]));
        LogCoverage::clear('splunk');
        $this->assertSame(100.0, LogCoverage::criticalWithoutLogging([$this->entity]), 'Plus aucun journal.');

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertTrue(LogCoverage::canView());
            $this->assertSame(['rights'], LogCoverage::import('splunk', $this->csv())['errors'], 'Lecture seule.');
            $this->assertFalse(LogCoverage::declarePlatform(['platform' => 'splunk', 'retention_days' => '1']));
            $this->assertFalse(LogCoverage::clear('splunk'));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        LogCoverage::reset();
        try {
            $this->assertFalse(LogCoverage::canView());
            $this->assertNull(LogCoverage::evidence('A.8.15'));
            $this->assertSame([], LogCoverage::pageData()['rows']);
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }
    }

    public function testRenderedPageAndNoLogContentStored(): void
    {
        LogCoverage::import('splunk', $this->csv());
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/log_coverage.html.twig',
            LogCoverage::pageData()
        );
        $this->assertStringContainsString('id="grc-log-list"', $html);
        $this->assertStringContainsString($this->host('srv-stale'), $html);
        $this->assertStringContainsString('inconnu-' . $this->suffix, $html, 'Hôtes non rapprochés listés.');
        $this->assertStringContainsString('name="import_log_hosts"', $html);
        $this->assertStringContainsString('name="declare_log_platform"', $html);

        global $DB;
        $columns = array_keys($DB->listFields(LogCoverageTables::HOSTS));
        sort($columns);
        $this->assertSame(
            ['date_import', 'entities_id', 'host', 'id', 'ip', 'last_event', 'platform', 'users_id_import'],
            $columns,
            'Aucune colonne pour le contenu d\'un journal.'
        );
    }
}

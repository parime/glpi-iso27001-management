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

use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\BusinessProcessRecovery;
use GlpiPlugin\Grcmanager\Install\BackupCoverageTables;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules as Rules;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamClient;

/**
 * Issue #838 : couverture des sauvegardes, sur des actifs, des exports et un faux serveur Veeam
 * fictifs d'une entité de test (seule entité active) et d'une entité cachée.
 */
final class BackupCoverageTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    /** @var array<string, int> */
    private array $pc = [];

    private int $process;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        BackupCoverage::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Sauvegardes ' . $this->suffix);
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
            'server_ok'     => $computer('srv-ok', $serverType),
            'server_old'    => $computer('srv-ancien', $serverType),
            'server_failed' => $computer('srv-echec', $serverType),
            'critical'      => $computer('poste-paie', $desktopType),
            'plain'         => $computer('poste', $desktopType),
            'hidden'        => $computer('srv-cache', $serverType, $hidden),
        ];
        foreach ($this->pc as $id) {
            $this->assertGreaterThan(0, $id);
        }
        global $DB;
        // Poste critique (intégrité élevée) dont le RPO propre est de 4 heures.
        $DB->insert('glpi_plugin_grcmanager_assetclassifications', [
            'itemtype' => \Computer::class, 'items_id' => $this->pc['critical'], 'integrity' => 'high',
            'rpo_hours' => 4,
        ]);
        // Processus métier (RPO 12 h) qui utilise le serveur « ancien », testé il y a deux mois.
        $DB->insert(BusinessProcess::getTable(), [
            'entities_id' => $this->entity, 'name' => 'PhpUnitDemo Paie ' . $this->suffix,
            'rpo_hours' => 12, 'rto_hours' => 8,
        ]);
        $this->process = (int) $DB->insertId();
        $DB->insert(BusinessProcessItem::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $this->process,
            'itemtype' => \Computer::class, 'items_id' => $this->pc['server_old'],
        ]);
        $DB->insert(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $this->process,
            'test_date' => date('Y-m-d', strtotime('-2 months')), 'measured_minutes' => 120,
            'itemtype' => \Computer::class, 'items_id' => $this->pc['server_old'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        BackupCoverage::reset();
        parent::tearDown();
    }

    private function host(string $name): string
    {
        return 'phpunitdemo-' . $name . '-' . $this->suffix;
    }

    private static function at(int $hoursAgo): string
    {
        return date('Y-m-d H:i:s', time() - $hoursAgo * 3600);
    }

    private function csv(): string
    {
        return "host;last success;status;job\n"
            . $this->host('srv-ok') . '.exemple.test;' . self::at(2) . ";Success;Serveurs\n"
            . $this->host('srv-ancien') . ';' . self::at(30) . ";Success;Serveurs\n"
            . $this->host('srv-echec') . ';' . self::at(10) . ";Failed;Serveurs\n"
            . $this->host('poste-paie') . ';' . self::at(6) . ";Success;Postes\n"
            . 'inconnu-' . $this->suffix . ';' . self::at(1) . ";Success;Serveurs\n"
            . $this->host('srv-cache') . ';' . self::at(1) . ";Success;Serveurs\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        foreach (BackupCoverage::pageData()['rows'] as $row) {
            if ($row['id'] === $id && $row['itemtype'] === \Computer::class) {
                return $row;
            }
        }
        $this->fail('Actif absent de la page.');
    }

    public function testImportMatchesAssetsAndComparesWithTheRpo(): void
    {
        $result = BackupCoverage::import('acronis', $this->csv());
        $this->assertSame([], $result['errors']);
        $this->assertSame(6, $result['imported']);
        $this->assertSame(4, $result['matched']);
        $this->assertSame(2, $result['unmatched'], 'Machine inconnue et ordinateur hors entités.');

        $ok = $this->row($this->pc['server_ok']);
        $this->assertSame(Rules::STATUS_OK, $ok['status'], 'Nom court rapproché, 2 h pour 48 h admises.');
        $this->assertSame(Rules::LIMIT_DEFAULT, $ok['limit_origin']);
        $this->assertSame('Serveurs', $ok['job']);

        $old = $this->row($this->pc['server_old']);
        $this->assertSame(Rules::STATUS_TOO_OLD, $old['status'], '30 h pour le RPO de 12 h du processus.');
        $this->assertSame(12, $old['allowed_hours']);
        $this->assertSame(Rules::LIMIT_PROCESS, $old['limit_origin']);
        $this->assertSame(Rules::RESTORE_RECENT, $old['restore'], 'Test de restauration il y a deux mois.');

        $this->assertSame(Rules::STATUS_FAILED, $this->row($this->pc['server_failed'])['status']);

        $critical = $this->row($this->pc['critical']);
        $this->assertTrue($critical['critical']);
        $this->assertSame(Rules::STATUS_TOO_OLD, $critical['status'], '6 h pour le RPO de 4 h de l\'actif.');
        $this->assertSame(Rules::LIMIT_ASSET, $critical['limit_origin']);
        $this->assertSame(Rules::RESTORE_NEVER, $critical['restore']);

        $data = BackupCoverage::pageData();
        $ids = array_column($data['rows'], 'id');
        $this->assertNotContains($this->pc['plain'], $ids, 'Poste ordinaire hors du périmètre par défaut.');
        $this->assertNotContains($this->pc['hidden'], $ids, 'Entité hors de la session.');
        $this->assertSame(Rules::STATUS_TOO_OLD, $data['rows'][0]['status'], 'Écarts d\'abord.');
        $this->assertSame(4, $data['summary']['concerned']['total']);
        $this->assertSame(50, $data['summary']['concerned']['rate']);
        $this->assertSame(1, $data['summary']['critical']['uncovered']);
        $this->assertSame(1, $data['summary']['critical_untested']);
        $this->assertContains('inconnu-' . $this->suffix, array_column($data['unmatched'], 'host'));
        $this->assertSame(['acronis'], array_column($data['sources'], 'source'));

        // Nouvel import du même outil : remplace le précédent.
        BackupCoverage::import('acronis', "host,last backup\n" . $this->host('poste-paie') . ',' . self::at(1) . "\n");
        $this->assertSame(
            1,
            countElementsInTable(BackupCoverageTables::HOSTS, ['entities_id' => $this->entity, 'source' => 'acronis'])
        );
        $this->assertSame(Rules::STATUS_OK, $this->row($this->pc['critical'])['status']);
        $this->assertSame(Rules::STATUS_NONE, $this->row($this->pc['server_ok'])['status']);

        $this->assertTrue(BackupCoverage::clear('acronis'));
        $this->assertSame(0, countElementsInTable(BackupCoverageTables::HOSTS, ['entities_id' => $this->entity]));
        $this->assertSame(['missing_host'], BackupCoverage::import('other', "count\n3\n")['errors']);
        $this->assertSame(['missing_date'], BackupCoverage::import('other', "host\nsrv\n")['errors']);
    }

    public function testVeeamReadingReplacesOnlyWhenComplete(): void
    {
        $points = [
            'data'       => [
                ['name' => $this->host('srv-ok'), 'creationTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)],
                ['name' => 'vm-inconnue-' . $this->suffix, 'creationTime' => gmdate('Y-m-d\TH:i:s\Z')],
            ],
            'pagination' => ['total' => 2],
        ];
        $jobs = [
            'data' => [
                ['name' => 'PhpUnitDemo Serveurs', 'type' => 'Backup', 'lastResult' => 'Failed', 'objectsCount' => 2],
                ['name' => 'PhpUnitDemo Postes', 'type' => 'Backup', 'lastResult' => 'Success', 'objectsCount' => 5],
            ],
        ];
        $fake = static fn (bool $fail): VeeamClient => new VeeamClient(
            'https://veeam.exemple.test:9419',
            'EXEMPLE\\lecteur',
            'mot-de-passe-fictif',
            VeeamClient::DEFAULT_API_VERSION,
            static function (string $method, string $url) use ($fail, $points, $jobs): array {
                if ($method === 'POST') {
                    return ['status' => 200, 'body' => '{"access_token":"jeton-fictif"}', 'retry_after' => 0];
                }
                if ($fail) {
                    return ['status' => 403, 'body' => '', 'retry_after' => 0];
                }
                $body = str_contains($url, VeeamClient::JOB_STATES_PATH) ? $jobs : $points;

                return ['status' => 200, 'body' => (string) json_encode($body), 'retry_after' => 0];
            }
        );

        $result = BackupCoverage::importFromVeeam($fake(false));
        $this->assertSame('', $result['error']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(2, $result['jobs']);
        $this->assertSame(Rules::STATUS_OK, $this->row($this->pc['server_ok'])['status']);
        $this->assertSame(['Veeam Backup & Replication'], $this->row($this->pc['server_ok'])['sources']);
        $jobRows = BackupCoverage::jobs();
        $this->assertSame(Rules::RESULT_FAILED, $jobRows[0]['last_result'], 'Travaux en échec d\'abord.');
        $this->assertSame(1, BackupCoverage::summary()['failed_jobs']);

        // Lecture refusée : rien n'est remplacé.
        $this->assertSame(VeeamClient::ERROR_FORBIDDEN, BackupCoverage::importFromVeeam($fake(true))['error']);
        $this->assertSame(2, countElementsInTable(BackupCoverageTables::HOSTS, ['entities_id' => $this->entity]));
        $this->assertSame(2, countElementsInTable(BackupCoverageTables::JOBS, ['entities_id' => $this->entity]));

        $this->assertTrue(BackupCoverage::clear('veeam'));
        $this->assertSame(0, countElementsInTable(BackupCoverageTables::JOBS, ['entities_id' => $this->entity]));
    }

    public function testScopeSettings(): void
    {
        BackupCoverage::import('synology', $this->csv());
        $saved = BackupCoverage::setSettings(['scope' => 'critical', 'max_age_hours' => '8', 'restore_months' => '1']);
        $this->assertSame(['scope' => 'critical', 'max_age_hours' => 8, 'restore_months' => 1], $saved);
        $this->assertSame($saved, BackupCoverage::getSettings());
        $this->assertSame([$this->pc['critical']], array_column(BackupCoverage::pageData()['rows'], 'id'));

        BackupCoverage::setSettings(['scope' => 'all', 'max_age_hours' => '1', 'restore_months' => '1']);
        $this->assertSame(Rules::STATUS_TOO_OLD, $this->row($this->pc['server_ok'])['status'], 'Une heure admise.');
        $this->assertSame(Rules::RESTORE_OLD, $this->row($this->pc['server_old'])['restore'], 'Un test par mois.');
        $this->assertSame(Rules::STATUS_NONE, $this->row($this->pc['plain'])['status']);
    }

    public function testEvidenceKriAndRights(): void
    {
        BackupCoverage::import('commvault', $this->csv());
        $evidence = BackupCoverage::evidence('A.8.13');
        $this->assertNotNull($evidence);
        $this->assertSame(['backups', 'restore_tests'], array_column($evidence['sources'], 'key'));
        $this->assertNotNull(BackupCoverage::evidence('A.5.30'));
        $this->assertNull(BackupCoverage::evidence('A.5.1'));
        $this->assertArrayHasKey('critical_assets_without_backup', RiskIndicator::getSources());
        $this->assertSame(100.0, BackupCoverage::criticalWithoutRecentBackup([$this->entity]), 'RPO de 4 h dépassé.');
        $this->assertNull(BackupCoverage::criticalWithoutRecentBackup([]));

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertTrue(BackupCoverage::canView());
            $refused = BackupCoverage::import('commvault', $this->csv());
            $this->assertSame(['rights'], $refused['errors'], 'Lecture seule.');
            $this->assertSame('rights', BackupCoverage::importFromVeeam()['error']);
            $this->assertFalse(BackupCoverage::clear('commvault'));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        BackupCoverage::reset();
        try {
            $this->assertFalse(BackupCoverage::canView());
            $this->assertNull(BackupCoverage::evidence('A.8.13'));
            $this->assertSame([], BackupCoverage::pageData()['rows']);
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }
    }

    public function testRenderedPageNeverShowsThePassword(): void
    {
        BackupCoverage::import('proxmox', $this->csv());
        \GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig::save(
            'https://veeam.exemple.test',
            'EXEMPLE\\lecteur',
            'mot-de-passe-fictif-838',
            '1.2-rev0',
            false
        );
        try {
            $data = BackupCoverage::pageData();
            $this->assertTrue($data['veeam']['has_password']);
            $this->assertSame('https://veeam.exemple.test:9419', $data['veeam']['url']);
            $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
                '@grcmanager/backup_coverage.html.twig',
                $data
            );
            $this->assertStringContainsString('id="grc-backup-list"', $html);
            $this->assertStringContainsString($this->host('srv-ancien'), $html);
            $this->assertStringContainsString('inconnu-' . $this->suffix, $html, 'Machines non rapprochées listées.');
            $this->assertStringContainsString('name="import_backup_hosts"', $html);
            $this->assertStringContainsString('name="fetch_veeam"', $html);
            $this->assertStringNotContainsString('mot-de-passe-fictif-838', $html);
            $this->assertArrayNotHasKey('password', $data['veeam']);
        } finally {
            \GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig::save('', '', '', '', true);
        }
        $this->assertFalse(\GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig::hasPassword());
    }
}

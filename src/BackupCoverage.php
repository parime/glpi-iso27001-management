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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Computer;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Install\BackupCoverageTables;
use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules as Rules;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamClient;
use GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryExerciseRules;
use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules;
use Html;
use NetworkEquipment;
use Session;

/**
 * Issue #838 : couverture des sauvegardes, pour les mesures A.8.13 (sauvegarde des informations)
 * et A.5.30 (préparation des TIC à la continuité).
 *
 * - Machines sauvegardées lues en lecture seule dans Veeam Backup & Replication (API REST, sur
 *   clic : points de restauration récents et état des travaux), ou importées par CSV depuis
 *   Acronis, Commvault, Synology Active Backup, Proxmox Backup Server ou un autre outil.
 * - Rapprochement avec les ordinateurs et matériels réseau de GLPI (nom, nom court, adresse IP),
 *   comme la couverture de la journalisation (#853) : dernière sauvegarde réussie, dernière
 *   tentative en échec, ancienneté comparée au RPO de l'actif ou de ses processus métier.
 * - Dernier test de restauration de chaque actif, repris des exercices de reprise (#733).
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour l'import, le connecteur et les réglages. Aucune sauvegarde n'est
 * lancée et rien n'est modifié dans l'outil de sauvegarde ni dans GLPI.
 */
class BackupCoverage extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const SETTINGS_KEY = 'backup_coverage_settings';

    /** Actifs, hôtes et travaux listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** Mesures dont la fiche affiche la preuve. */
    public const CONTROLS = ['A.8.13', 'A.5.30'];

    /** @var array<string, mixed>|null calcul de la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Backup coverage', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-database-export';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/backupcoverage.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Import, connecteur et réglages.
     */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function reset(): void
    {
        self::$memo = null;
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            'veeam'     => 'Veeam Backup & Replication',
            'acronis'   => 'Acronis Cyber Protect',
            'commvault' => 'Commvault',
            'synology'  => 'Synology Active Backup',
            'proxmox'   => 'Proxmox Backup Server',
            'other'     => __('Other backup tool', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_NONE    => [
                'label' => __('No backup', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-database-off',
                'help'  => __(
                    'No backup tool knows a successful backup of this asset (name or IP address).',
                    'grcmanager'
                ),
            ],
            Rules::STATUS_TOO_OLD => [
                'label' => __('Backup too old', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-clock-x',
                'help'  => __('The last successful backup is older than the maximum age allowed (RPO).', 'grcmanager'),
            ],
            Rules::STATUS_FAILED  => [
                'label' => __('Last backup failed', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-alert-triangle',
                'help'  => __(
                    'The last attempt failed; the previous successful backup is still within the maximum age.',
                    'grcmanager'
                ),
            ],
            Rules::STATUS_OK      => [
                'label' => __('Recent backup', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-database-check',
                'help'  => __('The last successful backup is within the maximum age allowed.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function resultInfos(): array
    {
        return [
            Rules::RESULT_SUCCESS => [
                'label' => __('Success', 'grcmanager'), 'color' => 'success', 'icon' => 'ti ti-check',
            ],
            Rules::RESULT_WARNING => [
                'label' => __('Warning', 'grcmanager'), 'color' => 'warning', 'icon' => 'ti ti-alert-triangle',
            ],
            Rules::RESULT_FAILED  => ['label' => __('Failed', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-x'],
            Rules::RESULT_UNKNOWN => [
                'label' => __('Unknown', 'grcmanager'), 'color' => 'secondary', 'icon' => 'ti ti-help-circle',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function restoreInfos(): array
    {
        return [
            Rules::RESTORE_RECENT => [
                'label' => __('Restore tested', 'grcmanager'), 'color' => 'success',
                'icon'  => 'ti ti-rotate-clockwise-2',
            ],
            Rules::RESTORE_FAILED => [
                'label' => __('Restore test failed', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-rotate-2',
            ],
            Rules::RESTORE_OLD    => [
                'label' => __('Restore test too old', 'grcmanager'), 'color' => 'warning', 'icon' => 'ti ti-history',
            ],
            Rules::RESTORE_NEVER  => [
                'label' => __('Restore never tested', 'grcmanager'), 'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function limitLabels(): array
    {
        return [
            Rules::LIMIT_ASSET   => __('RPO of the asset', 'grcmanager'),
            Rules::LIMIT_PROCESS => __('RPO of a business process', 'grcmanager'),
            Rules::LIMIT_DEFAULT => __('Default maximum age', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeLabels(): array
    {
        return [
            Rules::SCOPE_CRITICAL_SERVERS => __('Critical assets and servers', 'grcmanager'),
            Rules::SCOPE_CRITICAL         => __('Critical assets only', 'grcmanager'),
            Rules::SCOPE_ALL              => __('All computers and network devices', 'grcmanager'),
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            'missing_host' => __(
                'The file has no column for the machine name or IP address (for example host, hostname, vm, '
                    . 'device, client or backup-id).',
                'grcmanager'
            ),
            'missing_date' => __(
                'The file has no column for the date of the backup (for example last_success, last backup, '
                    . 'backup-time, end time or date).',
                'grcmanager'
            ),
            'rights'       => __('You do not have the right to import.', 'grcmanager'),
            default        => __('The file could not be read.', 'grcmanager'),
        };
    }

    /**
     * Message d'une erreur de lecture de Veeam (jamais le mot de passe ni le jeton).
     */
    public static function veeamErrorMessage(string $code): string
    {
        return match ($code) {
            VeeamClient::ERROR_CONFIG       => __(
                'Veeam is not configured: enter the address of the server, the account and its password.',
                'grcmanager'
            ),
            VeeamClient::ERROR_UNAUTHORIZED => __(
                'Veeam refused the account: check the user name (DOMAIN\\user) and the password.',
                'grcmanager'
            ),
            VeeamClient::ERROR_FORBIDDEN    => __(
                'Access refused by Veeam: give the account at least the Veeam Backup Viewer role.',
                'grcmanager'
            ),
            VeeamClient::ERROR_RATE_LIMITED => __('Veeam asks to slow down: try again in a few minutes.', 'grcmanager'),
            VeeamClient::ERROR_PRIVATE      => __(
                'The Veeam server has an internal address: an administrator must allow internal addresses in the '
                    . 'configuration page of the plugin.',
                'grcmanager'
            ) . ' ' . Http::glpi12ContextHint(),
            VeeamClient::ERROR_UNREACHABLE  => __(
                'The Veeam server could not be reached: check the address, the port (9419 by default), the network '
                    . 'access and that its certificate is trusted by the GLPI server.',
                'grcmanager'
            ),
            VeeamClient::ERROR_TOO_LARGE    => __(
                'Veeam returned too many restore points to be read at once: nothing was replaced. Import a CSV '
                    . 'export instead.',
                'grcmanager'
            ),
            VeeamClient::ERROR_STATUS       => __(
                'Veeam returned an error: check the REST API revision chosen for your version.',
                'grcmanager'
            ),
            default                         => __('Veeam returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Réglages.

    /**
     * @return array{scope: string, max_age_hours: int, restore_months: int}
     */
    public static function getSettings(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::SETTINGS_KEY]);

        return Rules::normalizeSettings(json_decode((string) ($values[self::SETTINGS_KEY] ?? ''), true));
    }

    /**
     * @param array<mixed> $settings
     * @return array{scope: string, max_age_hours: int, restore_months: int} réglages enregistrés
     */
    public static function setSettings(array $settings): array
    {
        $settings = Rules::normalizeSettings($settings);
        Config::setConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::SETTINGS_KEY => json_encode($settings)]);
        self::reset();

        return $settings;
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * RPO de chaque actif (classification) et des processus métier qui l'utilisent, avec les
     * tests de restauration de ces processus ou de l'actif (exercices de reprise, #733).
     *
     * @param array<string, array<string, mixed>> $assets
     * @param list<int>|null $entities
     * @return array{asset_rpo: array<string, int>, process_rpo: array<string, list<int>>,
     *     tests: array<string, array{date: string, failed: bool, process_id: int, process: string}>}
     */
    private static function objectives(array $assets, ?array $entities): array
    {
        global $DB;

        $byType = [];
        foreach ($assets as $asset) {
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        $assetRpo = [];
        $links = [];
        foreach ($byType as $itemtype => $ids) {
            foreach (
                $DB->request([
                    'SELECT' => ['items_id', 'rpo_hours'],
                    'FROM'   => AssetClassification::getTable(),
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids],
                ]) as $row
            ) {
                $assetRpo[$itemtype . ':' . (int) $row['items_id']] = max(0, (int) $row['rpo_hours']);
            }
            foreach (
                $DB->request([
                    'SELECT' => ['plugin_grcmanager_businessprocesses_id', 'items_id'],
                    'FROM'   => BusinessProcessItem::getTable(),
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids],
                ]) as $row
            ) {
                $processId = (int) $row['plugin_grcmanager_businessprocesses_id'];
                $links[$processId][] = $itemtype . ':' . (int) $row['items_id'];
            }
        }

        // Processus visibles (entités de la session, ou périmètre) : RPO et RTO.
        $processes = [];
        if ($links !== []) {
            $where = ['id' => array_keys($links)] + ($entities === null
                ? getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true)
                : ['entities_id' => $entities]);
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'rpo_hours', 'rto_hours'],
                    'FROM'   => BusinessProcess::getTable(),
                    'WHERE'  => $where,
                ]) as $row
            ) {
                $processes[(int) $row['id']] = [
                    'name' => (string) $row['name'],
                    'rpo'  => max(0, (int) $row['rpo_hours']),
                    'rto'  => max(0, (int) $row['rto_hours']),
                ];
            }
        }
        $processRpo = [];
        foreach ($processes as $id => $process) {
            foreach ($links[$id] as $key) {
                $processRpo[$key][] = $process['rpo'];
            }
        }

        // Tests de restauration : ceux qui désignent l'actif, sinon ceux de ses processus.
        $tests = [];
        if ($processes !== []) {
            foreach (
                $DB->request([
                    'SELECT' => [
                        'plugin_grcmanager_businessprocesses_id', 'test_date', 'measured_minutes',
                        'measured_rpo_minutes', 'itemtype', 'items_id',
                    ],
                    'FROM'   => BusinessProcessRecovery::getTable(),
                    'WHERE'  => [
                        'plugin_grcmanager_businessprocesses_id' => array_keys($processes),
                        'NOT'                                    => ['test_date' => null],
                    ],
                    'ORDER'  => ['test_date ASC', 'id ASC'],
                ]) as $row
            ) {
                $processId = (int) $row['plugin_grcmanager_businessprocesses_id'];
                $process = $processes[$processId];
                $rpo = RecoveryExerciseRules::sanitizeRpoMinutes($row['measured_rpo_minutes']);
                $test = [
                    'date'       => (string) $row['test_date'],
                    'failed'     => RecoveryStrategyRules::exceedsRto($process['rto'], (int) $row['measured_minutes'])
                        || RecoveryExerciseRules::exceedsRpo($process['rpo'], $rpo),
                    'process_id' => $processId,
                    'process'    => $process['name'],
                    'direct'     => false,
                ];
                $itemKey = (string) $row['itemtype'] . ':' . (int) $row['items_id'];
                if ((int) $row['items_id'] > 0 && isset($assets[$itemKey])) {
                    $tests[$itemKey] = ['direct' => true] + $test;
                    continue;
                }
                foreach ($links[$processId] as $key) {
                    // Un test qui désigne l'actif prime sur un test général de son processus.
                    if (!($tests[$key]['direct'] ?? false)) {
                        $tests[$key] = $test;
                    }
                }
            }
        }
        foreach ($tests as &$test) {
            unset($test['direct']);
        }
        unset($test);

        return ['asset_rpo' => $assetRpo, 'process_rpo' => $processRpo, 'tests' => $tests];
    }

    /**
     * Calcul complet : actifs concernés avec leur état, hôtes sauvegardés non rapprochés, outils.
     *
     * @param list<int>|null $entities périmètre (KRI) ; null = session
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     sources: array<string, array<string, mixed>>}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $settings = self::getSettings();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $inventory = LogCoverage::inventory($entities);
        $assets = $inventory['assets'];
        $labels = self::sourceLabels();

        $where = $entities === null
            ? getEntitiesRestrictCriteria(BackupCoverageTables::HOSTS)
            : ['entities_id' => $entities];
        $known = [];
        $sources = [];
        $unmatched = [];
        foreach ($DB->request(['FROM' => BackupCoverageTables::HOSTS, 'WHERE' => $where]) as $row) {
            $source = Rules::normalizeSource($row['source']);
            $host = [
                'host'         => (string) $row['host'],
                'ip'           => (string) $row['ip'],
                'job'          => (string) $row['job'],
                'last_success' => $row['last_success'] !== null ? (string) $row['last_success'] : null,
                'last_attempt' => $row['last_attempt'] !== null ? (string) $row['last_attempt'] : null,
                'last_result'  => self::result($row['last_result']),
            ];
            $sources[$source] ??= [
                'source' => $source, 'label' => $labels[$source], 'hosts' => 0, 'matched' => 0,
                'last_success' => null, 'date_import' => '', 'origin' => '',
            ];
            $sources[$source]['hosts']++;
            $sources[$source]['last_success'] = Rules::latest($sources[$source]['last_success'], $host['last_success']);
            $sources[$source]['date_import'] = max($sources[$source]['date_import'], (string) $row['date_import']);
            $sources[$source]['origin'] = (string) $row['origin'];
            $key = LogCoverageRules::match($host['host'], $host['ip'], $inventory['names'], $inventory['ips']);
            if ($key === null || !isset($assets[$key])) {
                $unmatched[] = $host + ['source' => $source];
                continue;
            }
            $sources[$source]['matched']++;
            $merged = Rules::merge($known[$key]['backup'] ?? null, $host);
            $known[$key] = [
                'backup'  => $merged,
                'sources' => ($known[$key]['sources'] ?? []) + [$source => $labels[$source]],
            ];
        }

        $objectives = self::objectives($assets, $entities);
        $rows = [];
        foreach ($assets as $key => $asset) {
            if (!Rules::isConcerned($settings['scope'], $asset['critical'], $asset['is_server'])) {
                continue;
            }
            $backup = $known[$key]['backup'] ?? null;
            $limit = Rules::allowedAge(
                $objectives['asset_rpo'][$key] ?? 0,
                $objectives['process_rpo'][$key] ?? [],
                $settings['max_age_hours']
            );
            $lastSuccess = $backup['last_success'] ?? null;
            $result = $backup['last_result'] ?? Rules::RESULT_UNKNOWN;
            $test = $objectives['tests'][$key] ?? null;
            $rows[] = $asset + [
                'status'         => Rules::status($lastSuccess, $result, $now, $limit['hours']),
                'last_success'   => $lastSuccess,
                'last_attempt'   => $backup['last_attempt'] ?? null,
                'last_result'    => $result,
                'job'            => $backup['job'] ?? '',
                'age_hours'      => Rules::ageHours($lastSuccess, $now),
                'allowed_hours'  => $limit['hours'],
                'limit_origin'   => $limit['origin'],
                'sources'        => array_values($known[$key]['sources'] ?? []),
                'restore'        => Rules::restoreStatus(
                    $test['date'] ?? null,
                    (bool) ($test['failed'] ?? false),
                    $now,
                    $settings['restore_months']
                ),
                'restore_date'   => $test['date'] ?? '',
                'restore_process_id' => $test['process_id'] ?? 0,
                'restore_process'    => $test['process'] ?? '',
            ];
        }

        return ['assets' => $rows, 'unmatched' => $unmatched, 'sources' => $sources];
    }

    private static function result(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return in_array(
            $value,
            [Rules::RESULT_SUCCESS, Rules::RESULT_WARNING, Rules::RESULT_FAILED, Rules::RESULT_UNKNOWN],
            true
        ) ? $value : Rules::RESULT_UNKNOWN;
    }

    /**
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     sources: array<string, array<string, mixed>>}
     */
    private static function data(): array
    {
        if (!self::canView()) {
            return ['assets' => [], 'unmatched' => [], 'sources' => []];
        }
        /** @var array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
         *     sources: array<string, array<string, mixed>>} $memo */
        $memo = self::$memo ??= self::compute(null);

        return $memo;
    }

    /**
     * Travaux de sauvegarde lus dans Veeam (entités de la session), en échec d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function jobs(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $rank = [Rules::RESULT_FAILED => 0, Rules::RESULT_WARNING => 1, Rules::RESULT_UNKNOWN => 2];
        $jobs = [];
        foreach (
            $DB->request([
                'FROM'  => BackupCoverageTables::JOBS,
                'WHERE' => getEntitiesRestrictCriteria(BackupCoverageTables::JOBS),
                'ORDER' => ['name'],
                'LIMIT' => self::MAX_ROWS,
            ]) as $row
        ) {
            $result = self::result($row['last_result']);
            $jobs[] = [
                'name'           => (string) $row['name'],
                'type'           => (string) $row['type'],
                'last_run_label' => $row['last_run'] !== null
                    ? (string) Html::convDateTime((string) $row['last_run'])
                    : '',
                'last_result'    => $result,
                'objects'        => (int) $row['objects'],
                'rank'           => $rank[$result] ?? 3,
            ];
        }
        usort($jobs, static fn (array $a, array $b): int => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);

        return $jobs;
    }

    /**
     * Synthèse : actifs concernés, actifs critiques, tests de restauration.
     *
     * @return array{concerned: array<string, mixed>, critical: array<string, mixed>, failed_jobs: int,
     *     critical_untested: int, sources: int}
     */
    public static function summary(): array
    {
        $data = self::data();
        $critical = array_values(array_filter(
            $data['assets'],
            static fn (array $row): bool => (bool) $row['critical']
        ));

        return [
            'concerned'         => Rules::summary($data['assets']),
            'critical'          => Rules::summary($critical),
            'failed_jobs'       => count(array_filter(
                self::jobs(),
                static fn (array $job): bool => $job['last_result'] === Rules::RESULT_FAILED
            )),
            'critical_untested' => count(array_filter(
                $critical,
                static fn (array $row): bool => $row['restore'] !== Rules::RESTORE_RECENT
            )),
            'sources'           => count($data['sources']),
        ];
    }

    /**
     * KRI « actifs critiques sans sauvegarde récente » (%) sur un périmètre d'entités, sans session.
     *
     * @param list<int> $perimeter
     */
    public static function criticalWithoutRecentBackup(array $perimeter): ?float
    {
        if ($perimeter === []) {
            return null;
        }
        $critical = array_values(array_filter(
            self::compute($perimeter)['assets'],
            static fn (array $row): bool => (bool) $row['critical']
        ));
        if ($critical === []) {
            return null;
        }
        $summary = Rules::summary($critical);

        return round($summary['uncovered'] * 100 / $summary['total'], 1);
    }

    // ------------------------------------------------------------------ Import et connecteur.

    /**
     * Importe un export CSV d'un outil de sauvegarde : remplace les lignes de cet outil dans
     * l'entité active. Rien n'est lancé dans l'outil ni modifié dans GLPI.
     *
     * @return array{imported: int, matched: int, unmatched: int, errors: list<string>, truncated: bool}
     */
    public static function import(string $source, string $raw): array
    {
        if (!self::canManage()) {
            return ['imported' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => ['rights'], 'truncated' => false];
        }
        $parsed = Rules::parseCsv($raw, date_default_timezone_get());
        if ($parsed['errors'] !== []) {
            return [
                'imported' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => $parsed['errors'], 'truncated' => false,
            ];
        }

        return self::store(Rules::normalizeSource($source), $parsed['rows'], [], 'csv')
            + ['errors' => [], 'truncated' => $parsed['truncated']];
    }

    /**
     * Lit Veeam Backup & Replication (sur clic) et range le résultat comme un import de Veeam de
     * l'entité active. Une lecture refusée ou incomplète ne remplace rien.
     *
     * @return array{imported: int, matched: int, unmatched: int, jobs: int, error: string}
     */
    public static function importFromVeeam(?VeeamClient $client = null): array
    {
        $empty = ['imported' => 0, 'matched' => 0, 'unmatched' => 0, 'jobs' => 0];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $empty;
        }
        $client ??= VeeamConfig::client();
        $fetch = $client->fetch(Session::getCurrentTime() ?? date('Y-m-d H:i:s'));
        if ($fetch['error'] !== '' || !$fetch['complete']) {
            return ['error' => $fetch['error'] !== '' ? $fetch['error'] : VeeamClient::ERROR_TOO_LARGE] + $empty;
        }

        return self::store('veeam', $fetch['hosts'], $fetch['jobs'], 'api')
            + ['jobs' => count($fetch['jobs']), 'error' => ''];
    }

    /**
     * Remplace les machines (et les travaux, pour le connecteur) d'un outil dans l'entité active.
     *
     * @param list<array{host: string, ip: string, job: string, last_success: ?string, last_attempt: ?string,
     *     last_result: string}> $rows
     * @param list<array{name: string, type: string, last_run: ?string, last_result: string, objects: int}> $jobs
     * @return array{imported: int, matched: int, unmatched: int}
     */
    private static function store(string $source, array $rows, array $jobs, string $origin): array
    {
        global $DB;

        $result = ['imported' => 0, 'matched' => 0, 'unmatched' => 0];
        $entity = (int) Session::getActiveEntity();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $inventory = LogCoverage::inventory(null);
        $DB->delete(BackupCoverageTables::HOSTS, ['source' => $source, 'entities_id' => $entity]);
        foreach ($rows as $row) {
            $DB->insert(BackupCoverageTables::HOSTS, [
                'entities_id'     => $entity,
                'source'          => $source,
                'origin'          => $origin,
                'host'            => $row['host'],
                'ip'              => $row['ip'],
                'job'             => $row['job'],
                'last_success'    => $row['last_success'],
                'last_attempt'    => $row['last_attempt'],
                'last_result'     => $row['last_result'],
                'users_id_import' => (int) Session::getLoginUserID(),
                'date_import'     => $now,
            ]);
            $result['imported']++;
            $matched = LogCoverageRules::match($row['host'], $row['ip'], $inventory['names'], $inventory['ips'])
                !== null;
            $result[$matched ? 'matched' : 'unmatched']++;
        }
        if ($origin === 'api') {
            $DB->delete(BackupCoverageTables::JOBS, ['source' => $source, 'entities_id' => $entity]);
            foreach ($jobs as $job) {
                $DB->insert(BackupCoverageTables::JOBS, [
                    'entities_id' => $entity,
                    'source'      => $source,
                    'name'        => $job['name'],
                    'type'        => $job['type'],
                    'last_run'    => $job['last_run'],
                    'last_result' => $job['last_result'],
                    'objects'     => $job['objects'],
                    'date_import' => $now,
                ]);
            }
        }
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'source'   => $source,
            'origin'   => $origin,
            'imported' => $result['imported'],
            'matched'  => $result['matched'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Efface l'import (et les travaux) d'un outil dans l'entité active.
     */
    public static function clear(string $source): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $source = Rules::normalizeSource($source);
        $where = ['source' => $source, 'entities_id' => (int) Session::getActiveEntity()];
        $ok = $DB->delete(BackupCoverageTables::HOSTS, $where);
        $DB->delete(BackupCoverageTables::JOBS, $where);
        AuditLog::record('purge', self::class, 0, 0, 'success', ['source' => $source]);
        self::reset();

        return (bool) $ok;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * Durée lisible (heures ou jours).
     */
    public static function formatHours(int $hours): string
    {
        return $hours >= 48 && $hours % 24 === 0
            ? sprintf(_n('%d day', '%d days', intdiv($hours, 24), 'grcmanager'), intdiv($hours, 24))
            : sprintf(_n('%d hour', '%d hours', $hours, 'grcmanager'), $hours);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $data = self::data();
        $rank = static fn (array $row): int => (int) array_search($row['status'], Rules::STATUSES, true);
        $listed = $data['assets'];
        usort(
            $listed,
            static fn (array $a, array $b): int => [$rank($a), !$a['critical'], $a['name']]
                <=> [$rank($b), !$b['critical'], $b['name']]
        );
        $shown = array_slice($listed, 0, self::MAX_ROWS);
        $canProcess = (bool) BusinessProcess::canView();
        foreach ($shown as &$row) {
            $row['last_success_label'] = $row['last_success'] !== null
                ? (string) Html::convDateTime((string) $row['last_success'])
                : '';
            $row['age_label'] = $row['age_hours'] !== null ? self::formatHours((int) $row['age_hours']) : '';
            $row['allowed_label'] = self::formatHours((int) $row['allowed_hours']);
            $row['restore_label'] = $row['restore_date'] !== ''
                ? (string) Html::convDate((string) $row['restore_date'])
                : '';
            $row['restore_url'] = $canProcess && $row['restore_process_id'] > 0
                ? BusinessProcess::getFormURLWithID((int) $row['restore_process_id'])
                : '';
            if (!$canProcess) {
                $row['restore_process'] = '';
            }
        }
        unset($row);
        $sources = $data['sources'];
        foreach ($sources as &$source) {
            $source['last_success_label'] = $source['last_success'] !== null
                ? (string) Html::convDateTime((string) $source['last_success'])
                : '';
            $source['date_import_label'] = $source['date_import'] !== ''
                ? (string) Html::convDateTime((string) $source['date_import'])
                : '';
        }
        unset($source);
        $labels = self::sourceLabels();
        $unmatched = array_slice($data['unmatched'], 0, self::MAX_ROWS);
        foreach ($unmatched as &$host) {
            $host['source_label'] = $labels[$host['source']];
            $host['last_success_label'] = $host['last_success'] !== null
                ? (string) Html::convDateTime((string) $host['last_success'])
                : '';
        }
        unset($host);
        $settings = self::getSettings();

        return [
            'summary'         => self::summary(),
            'infos'           => self::statusInfos(),
            'result_infos'    => self::resultInfos(),
            'restore_infos'   => self::restoreInfos(),
            'limit_labels'    => self::limitLabels(),
            'rows'            => $shown,
            'total_rows'      => count($listed),
            'unmatched'       => $unmatched,
            'total_unmatched' => count($data['unmatched']),
            'sources'         => array_values($sources),
            'jobs'            => self::jobs(),
            'source_labels'   => $labels,
            'settings'        => $settings,
            'max_age_label'   => self::formatHours($settings['max_age_hours']),
            'scopes'          => self::scopeLabels(),
            'can_manage'      => self::canManage(),
            'action'          => self::getSearchURL(),
            'veeam'           => [
                // Le mot de passe n'est jamais renvoyé, seulement sa présence.
                'url'          => VeeamConfig::rawUrl(),
                'username'     => VeeamConfig::username(),
                'api_version'  => VeeamConfig::apiVersion(),
                'api_versions' => VeeamClient::API_VERSIONS,
                'has_password' => VeeamConfig::hasPassword(),
                'configured'   => VeeamConfig::isConfigured(),
                'lookback'     => VeeamClient::LOOKBACK_DAYS,
            ],
            'processes_url'   => $canProcess ? BusinessProcess::getSearchURL() : '',
            'log_url'         => LogCoverage::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/backup_coverage.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.8.13 et A.5.30, sous la forme d'une liste de sources affichée par le
     * gabarit partagé de la conformité de configuration (#781, _configuration_evidence.html.twig) :
     * sauvegardes des actifs concernés, puis tests de restauration des actifs critiques. Null si la
     * mesure n'est pas concernée ou sans les droits.
     *
     * @return array{sources: list<array{key: string, icon: string, label: string, badges: list<array{color: string,
     *     icon: string, text: string}>, note: string, url: string, link: string}>}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CONTROLS, true) || !self::canView()) {
            return null;
        }

        return ['sources' => self::evidenceSources()];
    }

    /**
     * @return list<array{key: string, icon: string, label: string, badges: list<array{color: string,
     *     icon: string, text: string}>, note: string, url: string, link: string}>
     */
    public static function evidenceSources(): array
    {
        $summary = self::summary();
        $rate = $summary['concerned']['rate'];
        $date = sprintf(
            __('Computed on %s', 'grcmanager'),
            (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s'))
        );
        $ok = static fn (bool $gap, string $bad = 'danger'): string => $gap ? $bad : 'success';
        $backups = [
            'key'    => 'backups',
            'icon'   => self::getIcon(),
            'label'  => __('Backups', 'grcmanager'),
            'badges' => [],
            'note'   => $date,
            'url'    => self::getSearchURL(),
            'link'   => __('View the details', 'grcmanager'),
        ];
        if ($summary['sources'] === 0) {
            $backups['badges'][] = [
                'color' => 'secondary', 'icon' => 'ti ti-database-off',
                'text'  => __('No backup tool read yet', 'grcmanager'),
            ];
            $backups['link'] = __('Import results', 'grcmanager');
        } else {
            $backups['badges'][] = [
                'color' => match (true) {
                    $rate === null => 'secondary',
                    $rate >= 95    => 'success',
                    $rate >= 80    => 'warning',
                    default        => 'danger',
                },
                'icon'  => 'ti ti-database-export',
                'text'  => $rate === null
                    ? __('Backup coverage: not measured', 'grcmanager')
                    : sprintf(__('Recent backups: %d%%', 'grcmanager'), $rate),
            ];
        }
        $backups['badges'][] = [
            'color' => $summary['critical']['total'] === 0
                ? 'secondary'
                : $ok($summary['critical']['uncovered'] > 0),
            'icon'  => 'ti ti-alert-octagon',
            'text'  => sprintf(
                __('%d critical asset(s) without a recent backup', 'grcmanager'),
                $summary['critical']['uncovered']
            ),
        ];
        if ($summary['concerned']['failed'] + $summary['failed_jobs'] > 0) {
            $backups['badges'][] = [
                'color' => 'warning',
                'icon'  => 'ti ti-alert-triangle',
                'text'  => sprintf(
                    __('%1$d asset(s) whose last backup failed, %2$d job(s) failed', 'grcmanager'),
                    $summary['concerned']['failed'],
                    $summary['failed_jobs']
                ),
            ];
        }
        $restores = [
            'key'    => 'restore_tests',
            'icon'   => 'ti ti-rotate-clockwise-2',
            'label'  => __('Restore tests', 'grcmanager'),
            'badges' => [[
                'color' => $summary['critical']['total'] === 0
                    ? 'secondary'
                    : $ok($summary['critical_untested'] > 0, 'warning'),
                'icon'  => 'ti ti-history',
                'text'  => sprintf(
                    __('%d critical asset(s) without a recent restore test', 'grcmanager'),
                    $summary['critical_untested']
                ),
            ]],
            'note'   => '',
            'url'    => self::getSearchURL() . '#grc-backup-list',
            'link'   => __('View the details', 'grcmanager'),
        ];

        return [$backups, $restores];
    }
}

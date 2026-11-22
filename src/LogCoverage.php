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
use CommonDBTM;
use Computer;
use ComputerType;
use Config;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\LogCoverageTables;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Cve\OperatingSystemInventory;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules as Rules;
use Html;
use NetworkEquipment;
use Session;

/**
 * Issue #853 : couverture de la journalisation, pour les mesures A.8.15 (journalisation), A.8.16
 * (activités de surveillance) et A.8.17 (synchronisation des horloges).
 *
 * - Hôtes émetteurs lus en lecture seule dans le plugin Wazuh quand il est actif (agents actifs,
 *   dernier contact si le plugin le fournit), et importés par CSV depuis Graylog, Elastic ou
 *   OpenSearch, Splunk, un serveur syslog ou Wazuh : nom, adresse, dernier événement reçu.
 * - Rapprochement avec les ordinateurs et matériels réseau de GLPI (nom, nom court, adresse IP) :
 *   actifs concernés qui n'envoient rien, ou plus rien depuis N jours (réglable).
 * - Actifs concernés réglables : actifs critiques (classification C/I/D élevée ou publiés sur
 *   Internet, comme les correctifs de sécurité), serveurs et matériels réseau, ou tous.
 * - Durée de conservation et synchronisation des horloges déclarées par plateforme, comparées à la
 *   politique (réglable) et à l'obligation choisie dans le registre des obligations.
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour l'import, les réglages et les déclarations. Rien n'est lancé sur les
 * plateformes de journaux et rien n'est modifié dans GLPI.
 */
class LogCoverage extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const SETTINGS_KEY = 'log_coverage_settings';

    /** Actifs et hôtes listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** Colonnes facultatives du plugin Wazuh : nom, adresse et dernier contact de l'agent. */
    private const WAZUH_NAME_COLUMNS = ['name'];
    private const WAZUH_IP_COLUMNS = ['ip', 'ip_address'];
    private const WAZUH_LAST_COLUMNS = ['last_keepalive', 'lastkeepalive', 'date_last_keepalive', 'last_contact'];

    /** @var array<string, mixed>|null calcul de la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Logging coverage', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-search';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/logcoverage.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Import, réglages et déclarations des plateformes.
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
    public static function platformLabels(): array
    {
        return [
            'wazuh'   => 'Wazuh',
            'graylog' => 'Graylog',
            'elastic' => 'Elastic / OpenSearch',
            'splunk'  => 'Splunk',
            'syslog'  => __('Syslog server', 'grcmanager'),
            'other'   => __('Other log platform', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_SILENT => [
                'label' => __('No logs received', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-file-off',
                'help'  => __('No log platform knows this asset (name or IP address).', 'grcmanager'),
            ],
            Rules::STATUS_STALE  => [
                'label' => __('Logs stopped', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-clock-pause',
                'help'  => __('The last event received is older than the threshold.', 'grcmanager'),
            ],
            Rules::STATUS_OK     => [
                'label' => __('Logs received', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-file-check',
                'help'  => __('A log platform received an event from this asset recently.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeLabels(): array
    {
        return [
            Rules::SCOPE_CRITICAL_SERVERS => __('Critical assets, servers and network devices', 'grcmanager'),
            Rules::SCOPE_CRITICAL         => __('Critical assets only', 'grcmanager'),
            Rules::SCOPE_ALL              => __('All computers and network devices', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function retentionInfos(): array
    {
        return [
            Rules::RETENTION_OK         => [
                'label' => __('Long enough', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-check',
            ],
            Rules::RETENTION_SHORT      => [
                'label' => __('Too short', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-hourglass-low',
            ],
            Rules::RETENTION_UNDECLARED => [
                'label' => __('Not declared', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
            ],
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            'missing_host' => __(
                'The file has no column for the host name or IP address (for example host, hostname, source, '
                    . 'agent.name or ip).',
                'grcmanager'
            ),
            'rights'       => __('You do not have the right to import.', 'grcmanager'),
            default        => __('The file could not be read.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Réglages.

    /**
     * @return array{scope: string, policy_days: int, stale_days: int}
     */
    public static function getSettings(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::SETTINGS_KEY]);

        return Rules::normalizeSettings(json_decode((string) ($values[self::SETTINGS_KEY] ?? ''), true));
    }

    /**
     * @param array<mixed> $settings
     * @return array{scope: string, policy_days: int, stale_days: int} réglages enregistrés
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
     * Actifs (ordinateurs et matériels réseau) des entités données, ou de la session.
     *
     * @param list<int>|null $entities
     * @return array<string, array<string, mixed>> par clé « type:id »
     */
    private static function assets(?array $entities): array
    {
        global $DB;

        $excluded = ComplianceAudit::getExcludedStateIds();
        $itemtypes = [Computer::class => 'computer'];
        if ($entities !== null || (bool) NetworkEquipment::canView()) {
            $itemtypes[NetworkEquipment::class] = 'network';
        }
        $assets = [];
        foreach ($itemtypes as $itemtype => $kind) {
            $table = $itemtype::getTable();
            $where = ['is_deleted' => 0, 'is_template' => 0]
                + ($entities === null
                    ? getEntitiesRestrictCriteria($table, '', '', true)
                    : [$table . '.entities_id' => $entities]);
            if ($excluded !== []) {
                $where[] = ['NOT' => ['states_id' => $excluded]];
            }
            $select = ['id', 'name', 'entities_id'];
            if ($itemtype === Computer::class) {
                $select[] = 'computertypes_id';
            }
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $id = (int) $row['id'];
                $assets[$itemtype . ':' . $id] = [
                    'itemtype'    => $itemtype,
                    'kind'        => $kind,
                    'id'          => $id,
                    'name'        => (string) $row['name'],
                    'entities_id' => (int) $row['entities_id'],
                    'type_id'     => (int) ($row['computertypes_id'] ?? 0),
                ];
            }
        }

        return $assets;
    }

    /**
     * Classification C/I/D et publication sur Internet des actifs.
     *
     * @param array<string, array<string, mixed>> $assets
     * @return array{levels: array<string, array{confidentiality: string, integrity: string, availability: string}>,
     *     exposed: array<string, true>}
     */
    private static function criticality(array $assets): array
    {
        global $DB;

        $byType = [];
        foreach ($assets as $asset) {
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        $levels = [];
        $exposed = [];
        foreach ($byType as $itemtype => $ids) {
            foreach (
                $DB->request([
                    'SELECT' => ['items_id', 'confidentiality', 'integrity', 'availability'],
                    'FROM'   => AssetClassification::getTable(),
                    'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $ids],
                ]) as $row
            ) {
                $levels[$itemtype . ':' . (int) $row['items_id']] = [
                    'confidentiality' => (string) $row['confidentiality'],
                    'integrity'       => (string) $row['integrity'],
                    'availability'    => (string) $row['availability'],
                ];
            }
            foreach (
                $DB->request([
                    'SELECT' => ['destination_items_id'],
                    'FROM'   => NetworkFlow::getTable(),
                    'WHERE'  => [
                        'flow_type'            => 'internet_publication',
                        'is_active'            => 1,
                        'destination_itemtype' => $itemtype,
                        'destination_items_id' => $ids,
                    ],
                ]) as $row
            ) {
                $exposed[$itemtype . ':' . (int) $row['destination_items_id']] = true;
            }
        }

        return ['levels' => $levels, 'exposed' => $exposed];
    }

    /**
     * Index de rapprochement : noms complets et courts, adresses IP inventoriées.
     *
     * @param array<string, array<string, mixed>> $assets
     * @return array{names: array<string, string>, ips: array<string, string>}
     */
    private static function lookup(array $assets): array
    {
        global $DB;

        $names = [];
        $byType = [];
        foreach ($assets as $key => $asset) {
            $name = mb_strtolower(trim((string) $asset['name']));
            if ($name !== '') {
                $names[$name] ??= $key;
                $names[Rules::normalizeHost($name)] ??= $key;
            }
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        $ips = [];
        foreach ($byType as $itemtype => $ids) {
            foreach (
                $DB->request([
                    'SELECT' => ['mainitems_id', 'name'],
                    'FROM'   => 'glpi_ipaddresses',
                    'WHERE'  => ['mainitemtype' => $itemtype, 'mainitems_id' => $ids, 'is_deleted' => 0],
                ]) as $row
            ) {
                $address = mb_strtolower(trim((string) $row['name']));
                if ($address !== '') {
                    $ips[$address] ??= $itemtype . ':' . (int) $row['mainitems_id'];
                }
            }
        }

        return ['names' => $names, 'ips' => $ips];
    }

    /**
     * Agents Wazuh actifs, lus dans le plugin Wazuh (lecture seule), avec l'actif qui leur est
     * rattaché quand le plugin le donne. Liste vide si le plugin manque.
     *
     * @return list<array{host: string, ip: string, last_event: ?string, key: ?string}>
     */
    private static function wazuhHosts(): array
    {
        global $DB;

        $table = WazuhEvidenceProvider::agentTable();
        if ($table === null) {
            return [];
        }
        $optional = static function (array $candidates) use ($DB, $table): ?string {
            foreach ($candidates as $column) {
                if ($DB->fieldExists($table, $column)) {
                    return $column;
                }
            }

            return null;
        };
        $nameColumn = $optional(self::WAZUH_NAME_COLUMNS);
        $ipColumn = $optional(self::WAZUH_IP_COLUMNS);
        $lastColumn = $optional(self::WAZUH_LAST_COLUMNS);
        $select = array_values(array_filter(
            ['id', 'status', 'itemtype', 'item_id', $nameColumn, $ipColumn, $lastColumn]
        ));
        $hosts = [];
        try {
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => ['is_deleted' => 0]]) as $row) {
                if ((string) $row['status'] !== WazuhEvidence::ACTIVE_STATUS) {
                    continue;
                }
                $itemtype = (string) $row['itemtype'];
                $last = $lastColumn !== null ? trim((string) ($row[$lastColumn] ?? '')) : '';
                $hosts[(int) $row['id']] = [
                    'host'       => $nameColumn !== null ? (string) ($row[$nameColumn] ?? '') : '',
                    'ip'         => $ipColumn !== null ? (string) ($row[$ipColumn] ?? '') : '',
                    'last_event' => $last !== '' && !str_starts_with($last, '0000') ? $last : null,
                    'key'        => in_array($itemtype, [Computer::class, NetworkEquipment::class], true)
                        && (int) $row['item_id'] > 0 ? $itemtype . ':' . (int) $row['item_id'] : null,
                ];
            }
            $relation = WazuhEvidence::resolveTable(
                [WazuhEvidence::RELATION_TABLE],
                WazuhEvidence::RELATION_COLUMNS,
                static fn (string $name): bool => $DB->tableExists($name),
                static fn (string $name, string $column): bool => $DB->fieldExists($name, $column)
            );
            if ($relation !== null && $hosts !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['pluginwazuhagent_id', 'itemtype', 'items_id'],
                        'FROM'   => $relation,
                        'WHERE'  => [
                            'pluginwazuhagent_id' => array_keys($hosts),
                            'itemtype'            => [Computer::class, NetworkEquipment::class],
                        ],
                    ]) as $row
                ) {
                    $agent = (int) $row['pluginwazuhagent_id'];
                    if (isset($hosts[$agent]) && $hosts[$agent]['key'] === null) {
                        $hosts[$agent]['key'] = (string) $row['itemtype'] . ':' . (int) $row['items_id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_values($hosts);
    }

    /**
     * Description des actifs : nom affiché, lien, entité, type, serveur, critique, publié sur
     * Internet. Partagée avec la couverture des sauvegardes (issue #838).
     *
     * @param array<string, array<string, mixed>> $assets
     * @param array{levels: array<string, array{confidentiality: string, integrity: string, availability: string}>,
     *     exposed: array<string, true>} $criticality
     * @return array<string, array{key: string, itemtype: string, id: int, name: string, url: string, kind: string,
     *     type: string, entity: string, is_server: bool, critical: bool, exposed: bool}>
     */
    private static function describe(array $assets, array $criticality): array
    {
        global $DB;

        // Types et systèmes des ordinateurs (serveurs).
        $computerIds = [];
        $typeIds = [];
        foreach ($assets as $asset) {
            if ($asset['itemtype'] === Computer::class) {
                $computerIds[] = (int) $asset['id'];
                $typeIds[(int) $asset['type_id']] = true;
            }
        }
        $systems = OperatingSystemInventory::forComputers($computerIds);
        $typeNames = [];
        unset($typeIds[0]);
        if ($typeIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => ComputerType::getTable(),
                    'WHERE'  => ['id' => array_keys($typeIds)],
                ]) as $row
            ) {
                $typeNames[(int) $row['id']] = (string) $row['name'];
            }
        }

        $entityNames = [];
        $described = [];
        foreach ($assets as $key => $asset) {
            $levels = $criticality['levels'][$key]
                ?? ['confidentiality' => '', 'integrity' => '', 'availability' => ''];
            $exposed = isset($criticality['exposed'][$key]);
            $isNetwork = $asset['kind'] === 'network';
            $typeName = $typeNames[(int) $asset['type_id']] ?? '';
            $entityId = (int) $asset['entities_id'];
            $entityNames[$entityId] ??= (string) Dropdown::getDropdownName('glpi_entities', $entityId);
            $itemtype = (string) $asset['itemtype'];
            $name = (string) $asset['name'];
            $described[$key] = [
                'key'       => $key,
                'itemtype'  => $itemtype,
                'id'        => (int) $asset['id'],
                'name'      => $name !== '' ? $name : sprintf('%s %d', $itemtype::getTypeName(1), (int) $asset['id']),
                'url'       => $itemtype::getFormURLWithID((int) $asset['id']),
                'kind'      => (string) $asset['kind'],
                'type'      => $isNetwork ? NetworkEquipment::getTypeName(1) : $typeName,
                'entity'    => $entityNames[$entityId],
                'is_server' => !$isNetwork
                    && PatchLagRules::isServer($typeName, (string) ($systems[(int) $asset['id']]['name'] ?? '')),
                'critical'  => Rules::isCritical(
                    $levels['confidentiality'],
                    $levels['integrity'],
                    $levels['availability'],
                    $exposed
                ),
                'exposed'   => $exposed,
            ];
        }

        return $described;
    }

    /**
     * Inventaire des actifs (ordinateurs et matériels réseau) des entités données, ou de la
     * session, décrits par describe(), avec l'index de rapprochement par nom et adresse IP.
     * Partagé avec la couverture des sauvegardes (issue #838).
     *
     * @param list<int>|null $entities
     * @return array{assets: array<string, array{key: string, itemtype: string, id: int, name: string, url: string,
     *     kind: string, type: string, entity: string, is_server: bool, critical: bool, exposed: bool}>,
     *     names: array<string, string>, ips: array<string, string>}
     */
    public static function inventory(?array $entities): array
    {
        $assets = self::assets($entities);
        $lookup = self::lookup($assets);

        return ['assets' => self::describe($assets, self::criticality($assets))] + $lookup;
    }

    /**
     * Calcul complet : actifs avec leur état, hôtes importés non rapprochés, plateformes.
     *
     * @param list<int>|null $entities périmètre (tâche planifiée, KRI) ; null = session
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     platforms: array<string, array<string, mixed>>}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $settings = self::getSettings();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $assets = self::assets($entities);
        $criticality = self::criticality($assets);
        $lookup = self::lookup($assets);
        $labels = self::platformLabels();

        // Hôtes émetteurs : Wazuh lu en direct, puis les imports des entités.
        $hosts = [];
        foreach (self::wazuhHosts() as $host) {
            $hosts[] = $host + ['platform' => 'wazuh', 'live' => true, 'date_import' => ''];
        }
        $hostWhere = $entities === null
            ? getEntitiesRestrictCriteria(LogCoverageTables::HOSTS)
            : ['entities_id' => $entities];
        foreach ($DB->request(['FROM' => LogCoverageTables::HOSTS, 'WHERE' => $hostWhere]) as $row) {
            $hosts[] = [
                'host'        => (string) $row['host'],
                'ip'          => (string) $row['ip'],
                'last_event'  => $row['last_event'] !== null ? (string) $row['last_event'] : null,
                'key'         => null,
                'platform'    => Rules::normalizePlatform($row['platform']),
                'live'        => false,
                'date_import' => (string) $row['date_import'],
            ];
        }

        $received = [];
        $platforms = [];
        $unmatched = [];
        foreach ($hosts as $host) {
            $key = $host['key'] ?? Rules::match($host['host'], $host['ip'], $lookup['names'], $lookup['ips']);
            $platform = $host['platform'];
            $platforms[$platform] ??= [
                'platform' => $platform, 'label' => $labels[$platform], 'hosts' => 0, 'matched' => 0,
                'last_event' => null, 'date_import' => '', 'live' => false,
            ];
            $platforms[$platform]['hosts']++;
            $platforms[$platform]['live'] = $platforms[$platform]['live'] || $host['live'];
            $platforms[$platform]['last_event'] = Rules::latest(
                $platforms[$platform]['last_event'],
                $host['last_event']
            );
            $platforms[$platform]['date_import'] = max($platforms[$platform]['date_import'], $host['date_import']);
            if ($key === null || !isset($assets[$key])) {
                if (!$host['live']) {
                    $unmatched[] = $host;
                }
                continue;
            }
            $platforms[$platform]['matched']++;
            $received[$key] ??= ['last_event' => null, 'platforms' => [], 'undated' => false];
            $received[$key]['platforms'][$platform] = $labels[$platform];
            if ($host['last_event'] === null) {
                // Hôte connu sans date (agent Wazuh actif, export sans colonne de date) : émetteur.
                $received[$key]['undated'] = true;
            } else {
                $received[$key]['last_event'] = Rules::latest($received[$key]['last_event'], $host['last_event']);
            }
        }

        $rows = [];
        foreach (self::describe($assets, $criticality) as $key => $asset) {
            $isNetwork = $asset['kind'] === 'network';
            if (!Rules::isConcerned($settings['scope'], $asset['critical'], $asset['is_server'], $isNetwork)) {
                continue;
            }
            $info = $received[$key] ?? null;
            $last = $info['last_event'] ?? null;
            $rows[] = $asset + [
                'status'     => Rules::status(
                    $info !== null,
                    ($info['undated'] ?? false) ? null : $last,
                    $now,
                    $settings['stale_days']
                ),
                'last_event' => $last,
                'age'        => Rules::ageDays($last, $now),
                'platforms'  => array_values($info['platforms'] ?? []),
            ];
        }

        return ['assets' => $rows, 'unmatched' => $unmatched, 'platforms' => $platforms];
    }

    /**
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     platforms: array<string, array<string, mixed>>}
     */
    private static function data(): array
    {
        if (!self::canView()) {
            return ['assets' => [], 'unmatched' => [], 'platforms' => []];
        }
        /** @var array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
         *     platforms: array<string, array<string, mixed>>} $memo */
        $memo = self::$memo ??= self::compute(null);

        return $memo;
    }

    /**
     * Déclarations des plateformes (conservation, obligation, horloges) dans les entités de la
     * session, complétées des plateformes utilisées sans déclaration.
     *
     * @param array<string, array<string, mixed>> $used plateformes qui ont des hôtes
     * @return list<array<string, mixed>>
     */
    public static function declarations(array $used = []): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $settings = self::getSettings();
        $labels = self::platformLabels();
        $rows = [];
        $declared = [];
        foreach (
            $DB->request([
                'FROM'  => LogCoverageTables::PLATFORMS,
                'WHERE' => getEntitiesRestrictCriteria(LogCoverageTables::PLATFORMS),
                'ORDER' => ['platform', 'entities_id'],
            ]) as $row
        ) {
            $platform = Rules::normalizePlatform($row['platform']);
            $declared[$platform] = true;
            $obligationId = (int) $row['plugin_grcmanager_complianceobligations_id'];
            $obligationDays = $obligationId > 0 ? (int) $row['obligation_days'] : 0;
            $required = Rules::requiredDays($settings['policy_days'], $obligationDays);
            $rows[] = [
                'platform'        => $platform,
                'label'           => $labels[$platform],
                'entities_id'     => (int) $row['entities_id'],
                'entity'          => (string) Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'retention_days'  => (int) $row['retention_days'],
                'required_days'   => $required,
                'retention'       => Rules::retentionStatus((int) $row['retention_days'], $required),
                'obligation_id'   => $obligationId,
                'obligation'      => self::obligationName($obligationId),
                'obligation_url'  => $obligationId > 0 ? ComplianceObligation::getFormURLWithID($obligationId) : '',
                'obligation_days' => $obligationDays,
                'ntp'             => (bool) $row['is_ntp_declared'],
                'ntp_server'      => (string) $row['ntp_server'],
                'comment'         => (string) ($row['comment'] ?? ''),
                'declared'        => true,
            ];
        }
        foreach ($used as $platform => $info) {
            if (isset($declared[$platform])) {
                continue;
            }
            $rows[] = [
                'platform'        => $platform,
                'label'           => $labels[$platform] ?? $platform,
                'entities_id'     => (int) Session::getActiveEntity(),
                'entity'          => '',
                'retention_days'  => 0,
                'required_days'   => $settings['policy_days'],
                'retention'       => Rules::RETENTION_UNDECLARED,
                'obligation_id'   => 0,
                'obligation'      => '',
                'obligation_url'  => '',
                'obligation_days' => 0,
                'ntp'             => false,
                'ntp_server'      => '',
                'comment'         => '',
                'declared'        => false,
            ];
        }

        return $rows;
    }

    /**
     * Obligations visibles du registre, pour le choix de celle qui impose une conservation.
     *
     * @return array<int, string>
     */
    public static function obligationChoices(): array
    {
        global $DB;

        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => ComplianceObligation::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(ComplianceObligation::getTable(), '', '', true),
                'ORDER'  => 'title',
                'LIMIT'  => 500,
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }

        return $choices;
    }

    /**
     * Titre d'une obligation du registre, vide si elle n'existe plus.
     */
    private static function obligationName(int $id): string
    {
        $obligation = new ComplianceObligation();
        if ($id <= 0 || !$obligation->getFromDB($id)) {
            return '';
        }

        return (string) ($obligation->fields['title'] ?? '');
    }

    /**
     * Issue #837 : ligne d'un actif pour la vue Sécurité 360°, null si aucun hôte émetteur n'a encore été
     * importé ou si l'actif n'est pas concerné (calcul du module, gardé en mémoire pour la requête).
     *
     * @return array<string, mixed>|null
     */
    public static function assetRow(CommonDBTM $item): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $data = self::data();
        if ($data['platforms'] === []) {
            return null;
        }
        $key = $item::class . ':' . (int) $item->getID();
        foreach ($data['assets'] as $row) {
            if (($row['key'] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Synthèse : actifs concernés, actifs critiques, conservation et horloges.
     *
     * @return array{concerned: array<string, mixed>, critical: array<string, mixed>, retention_gaps: int,
     *     ntp_missing: int, platforms: int}
     */
    public static function summary(): array
    {
        $data = self::data();
        $declarations = self::declarations($data['platforms']);

        return [
            'concerned'      => Rules::summary($data['assets']),
            'critical'       => Rules::summary(array_values(array_filter(
                $data['assets'],
                static fn (array $row): bool => (bool) $row['critical']
            ))),
            'retention_gaps' => count(array_filter(
                $declarations,
                static fn (array $row): bool => $row['retention'] !== Rules::RETENTION_OK
            )),
            'ntp_missing'    => count(array_filter($declarations, static fn (array $row): bool => !$row['ntp'])),
            'platforms'      => count($declarations),
        ];
    }

    /**
     * KRI « actifs critiques sans journalisation » (%) sur un périmètre d'entités, sans session.
     *
     * @param list<int> $perimeter
     */
    public static function criticalWithoutLogging(array $perimeter): ?float
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

    // ------------------------------------------------------------------ Import et déclarations.

    /**
     * Importe un export CSV d'hôtes émetteurs : remplace les lignes de cette plateforme dans
     * l'entité active. Rien n'est lancé sur la plateforme ni modifié dans GLPI.
     *
     * @return array{imported: int, matched: int, unmatched: int, errors: list<string>, truncated: bool}
     */
    public static function import(string $platform, string $raw): array
    {
        global $DB;

        $result = ['imported' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'truncated' => false];
        if (!self::canManage()) {
            $result['errors'][] = 'rights';

            return $result;
        }
        $platform = Rules::normalizePlatform($platform);
        $parsed = Rules::parseCsv($raw, date_default_timezone_get());
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }
        $entity = (int) Session::getActiveEntity();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $lookup = self::lookup(self::assets(null));
        $DB->delete(LogCoverageTables::HOSTS, ['platform' => $platform, 'entities_id' => $entity]);
        foreach ($parsed['rows'] as $row) {
            $DB->insert(LogCoverageTables::HOSTS, [
                'entities_id'     => $entity,
                'platform'        => $platform,
                'host'            => $row['host'],
                'ip'              => $row['ip'],
                'last_event'      => $row['last_event'],
                'users_id_import' => (int) Session::getLoginUserID(),
                'date_import'     => $now,
            ]);
            $result['imported']++;
            $matched = Rules::match($row['host'], $row['ip'], $lookup['names'], $lookup['ips']) !== null;
            $result[$matched ? 'matched' : 'unmatched']++;
        }
        $result['truncated'] = $parsed['truncated'];
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'platform' => $platform,
            'imported' => $result['imported'],
            'matched'  => $result['matched'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Efface l'import d'une plateforme dans l'entité active.
     */
    public static function clear(string $platform): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $platform = Rules::normalizePlatform($platform);
        $ok = $DB->delete(LogCoverageTables::HOSTS, [
            'platform'    => $platform,
            'entities_id' => (int) Session::getActiveEntity(),
        ]);
        AuditLog::record('purge', self::class, 0, 0, 'success', ['platform' => $platform]);
        self::reset();

        return (bool) $ok;
    }

    /**
     * Déclare, pour une plateforme et l'entité active, la conservation, l'obligation qui l'impose et
     * la synchronisation des horloges. Une obligation non visible est ignorée.
     *
     * @param array<mixed> $input
     */
    public static function declarePlatform(array $input): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $platform = Rules::normalizePlatform($input['platform'] ?? '');
        $obligationId = (int) ($input['plugin_grcmanager_complianceobligations_id'] ?? 0);
        $obligation = new ComplianceObligation();
        if ($obligationId > 0 && (!$obligation->getFromDB($obligationId) || !$obligation->canViewItem())) {
            $obligationId = 0;
        }
        $days = static fn (mixed $value): int => is_numeric($value)
            ? max(0, min(Rules::MAX_DAYS, (int) $value))
            : 0;
        $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $entity = (int) Session::getActiveEntity();
        $fields = [
            'retention_days'                             => $days($input['retention_days'] ?? 0),
            'plugin_grcmanager_complianceobligations_id' => $obligationId,
            'obligation_days'                            => $obligationId > 0
                ? $days($input['obligation_days'] ?? 0)
                : 0,
            'is_ntp_declared'                            => !empty($input['is_ntp_declared']) ? 1 : 0,
            'ntp_server'                                 => mb_substr($text($input['ntp_server'] ?? ''), 0, 255),
            'comment'                                    => mb_substr($text($input['comment'] ?? ''), 0, 2000),
            'users_id'                                   => (int) Session::getLoginUserID(),
            'date_mod'                                   => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ];
        $where = ['entities_id' => $entity, 'platform' => $platform];
        $ok = countElementsInTable(LogCoverageTables::PLATFORMS, $where) > 0
            ? $DB->update(LogCoverageTables::PLATFORMS, $fields, $where)
            : $DB->insert(LogCoverageTables::PLATFORMS, $fields + $where);
        AuditLog::record('update', self::class, 0, 0, 'success', [
            'platform'       => $platform,
            'retention_days' => $fields['retention_days'],
            'ntp'            => (bool) $fields['is_ntp_declared'],
        ]);
        self::reset();

        return (bool) $ok;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $data = self::data();
        $rank = static fn (array $row): int => array_search($row['status'], Rules::STATUSES, true) ?: 0;
        $listed = $data['assets'];
        usort(
            $listed,
            static fn (array $a, array $b): int => [$rank($a), !$a['critical'], $a['name']]
                <=> [$rank($b), !$b['critical'], $b['name']]
        );
        $shown = array_slice($listed, 0, self::MAX_ROWS);
        foreach ($shown as &$row) {
            $row['last_event_label'] = $row['last_event'] !== null
                ? (string) Html::convDateTime($row['last_event'])
                : '';
        }
        unset($row);
        $platforms = $data['platforms'];
        foreach ($platforms as &$platform) {
            $platform['last_event_label'] = $platform['last_event'] !== null
                ? (string) Html::convDateTime($platform['last_event'])
                : '';
            $platform['date_import_label'] = $platform['date_import'] !== ''
                ? (string) Html::convDateTime($platform['date_import'])
                : '';
        }
        unset($platform);
        $unmatched = array_slice($data['unmatched'], 0, self::MAX_ROWS);
        $labels = self::platformLabels();
        foreach ($unmatched as &$host) {
            $host['platform_label'] = $labels[$host['platform']];
            $host['last_event_label'] = $host['last_event'] !== null
                ? (string) Html::convDateTime($host['last_event'])
                : '';
        }
        unset($host);
        $settings = self::getSettings();

        return [
            'summary'          => self::summary(),
            'infos'            => self::statusInfos(),
            'retention_infos'  => self::retentionInfos(),
            'rows'             => $shown,
            'total_rows'       => count($listed),
            'unmatched'        => $unmatched,
            'total_unmatched'  => count($data['unmatched']),
            'platforms'        => array_values($platforms),
            'declarations'     => self::declarations($platforms),
            'platform_labels'  => $labels,
            'settings'         => $settings,
            'scopes'           => self::scopeLabels(),
            'wazuh_available'  => WazuhEvidenceProvider::isAvailable(),
            'can_manage'       => self::canManage(),
            // Issue #1044 : les réglages se modifient dans l'onglet Parc de la configuration du plugin.
            'settings_url'     => \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl(
                \GlpiPlugin\Grcmanager\Config\Block\LogCoverageBlock::ANCHOR
            ),
            'active_entity'    => (int) Session::getActiveEntity(),
            'action'           => self::getSearchURL(),
            'obligations'      => self::obligationChoices(),
            'obligations_url'  => ComplianceObligation::getSearchURL(),
            'audit_url'        => ComplianceAudit::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/log_coverage.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.8.15, A.8.16 et A.8.17 : couverture des actifs concernés, actifs
     * critiques sans journaux, conservation et horloges. Null si la mesure n'est pas concernée ou
     * sans les droits.
     *
     * @return array{rate: ?int, concerned: int, silent: int, stale: int, critical_uncovered: int,
     *     retention_gaps: int, ntp_missing: int, platforms: int, date: string, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.15', 'A.8.16', 'A.8.17'], true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();

        return [
            'rate'               => $summary['concerned']['rate'],
            'concerned'          => (int) $summary['concerned']['total'],
            'silent'             => (int) $summary['concerned']['silent'],
            'stale'              => (int) $summary['concerned']['stale'],
            'critical_uncovered' => (int) $summary['critical']['uncovered'],
            'retention_gaps'     => $summary['retention_gaps'],
            'ntp_missing'        => $summary['ntp_missing'],
            'platforms'          => $summary['platforms'],
            'date'               => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'                => self::getSearchURL(),
        ];
    }
}

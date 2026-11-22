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

use CommonDBTM;
use CommonGLPI;
use Computer;
use ComputerType;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\ZabbixTables;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules;
use GlpiPlugin\Grcmanager\Services\Cve\OperatingSystemInventory;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixRules as Rules;
use Html;
use NetworkEquipment;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Session;
use Ticket;

/**
 * Issue #836 : supervision lue dans Zabbix, en LECTURE SEULE, pour les mesures A.8.16 (activités de
 * surveillance), A.8.6 (dimensionnement) et A.5.30 (préparation des TIC à la continuité).
 *
 * - Lecture sur clic (ou par la tâche automatique, désactivée par défaut) de l'API JSON-RPC du
 *   serveur Zabbix configuré : hôtes, problèmes de la période et problèmes ouverts, disponibilité
 *   des services (SLA). Une lecture en échec ne remplace pas la précédente.
 * - Hôtes rapprochés des ordinateurs et matériels réseau de GLPI (numéro de série, nom, nom court,
 *   adresse IP) : actifs concernés non supervisés (critiques d'abord), hôtes non rapprochés.
 * - Disponibilité mesurée par actif (temps sans problème de gravité haute sur la période) et plus
 *   long arrêt comparé au RTO des processus métier qui dépendent de l'actif ; disponibilité des
 *   services comparée à leur SLO, service rattaché à un processus métier.
 * - Problèmes de saturation (disque, mémoire, processeur) de la période (A.8.6).
 * - Un problème de gravité haute peut être proposé comme incident de sécurité ou ticket, sur clic
 *   seulement, jamais automatiquement.
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour la connexion, la lecture et les réglages. Rien n'est modifié dans
 * Zabbix ; dans GLPI, seuls l'incident ou le ticket demandés sont créés.
 */
class ZabbixMonitoring extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const CRON_NAME = 'zabbix';

    /** Actifs, hôtes et problèmes listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** Numéros de série trop génériques pour rapprocher un hôte. */
    private const GENERIC_SERIALS = [
        'to be filled by o.e.m.', 'default string', 'system serial number', 'none', 'n/a', '0', '123456789',
    ];

    /** @var array<string, mixed>|null calcul de la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Zabbix monitoring', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-activity-heartbeat';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/zabbixmonitoring.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ)
            && (bool) Session::haveRight(Computer::$rightname, READ);
    }

    /**
     * Connexion, lecture, réglages et rattachement d'un service à un processus métier.
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
     * @return array<int, array{label: string, color: string}>
     */
    public static function severityInfos(): array
    {
        return [
            Rules::SEVERITY_NOT_CLASSIFIED => ['label' => __('Not classified', 'grcmanager'), 'color' => 'secondary'],
            Rules::SEVERITY_INFORMATION    => ['label' => __('Information', 'grcmanager'), 'color' => 'azure'],
            Rules::SEVERITY_WARNING        => ['label' => __('Warning', 'grcmanager'), 'color' => 'yellow'],
            Rules::SEVERITY_AVERAGE        => ['label' => __('Average', 'grcmanager'), 'color' => 'orange'],
            Rules::SEVERITY_HIGH           => ['label' => __('High', 'grcmanager'), 'color' => 'red'],
            Rules::SEVERITY_DISASTER       => ['label' => __('Disaster', 'grcmanager'), 'color' => 'purple'],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_MONITORED   => [
                'label' => __('Monitored', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-activity-heartbeat',
            ],
            Rules::STATUS_UNAVAILABLE => [
                'label' => __('Monitored, unreachable', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-plug-connected-x',
            ],
            Rules::STATUS_DISABLED    => [
                'label' => __('Monitoring disabled in Zabbix', 'grcmanager'),
                'color' => 'orange',
                'icon'  => 'ti ti-player-pause',
            ],
            Rules::STATUS_MISSING     => [
                'label' => __('Not monitored', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-eye-off',
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
     * @return array<string, array{label: string, icon: string}>
     */
    public static function capacityInfos(): array
    {
        return [
            Rules::CAPACITY_STORAGE => ['label' => __('Disk space', 'grcmanager'), 'icon' => 'ti ti-database'],
            Rules::CAPACITY_MEMORY  => ['label' => __('Memory', 'grcmanager'), 'icon' => 'ti ti-cpu-2'],
            Rules::CAPACITY_CPU     => ['label' => __('Processor', 'grcmanager'), 'icon' => 'ti ti-cpu'],
        ];
    }

    /**
     * Message d'une erreur de lecture (jamais le jeton ni l'adresse complète).
     */
    public static function errorMessage(string $code): string
    {
        return match ($code) {
            ZabbixClient::ERROR_CONFIG       => __(
                'Enter the address of the Zabbix server and an API token first.',
                'grcmanager'
            ),
            ZabbixClient::ERROR_UNAUTHORIZED => __(
                'Zabbix refused the API token: check that it is valid, not expired, and that its user is enabled.',
                'grcmanager'
            ),
            ZabbixClient::ERROR_FORBIDDEN    => __(
                'Zabbix refused access: give the user of the token read access to the host groups and services.',
                'grcmanager'
            ),
            ZabbixClient::ERROR_INTERNAL     => __(
                'The Zabbix server has an internal address, refused by default: allow internal addresses in the '
                    . 'plugin configuration page (Outgoing connections).',
                'grcmanager'
            ),
            ZabbixClient::ERROR_UNREACHABLE  => __(
                'The Zabbix server could not be reached: check the address, the network access or the GLPI proxy.',
                'grcmanager'
            ),
            ZabbixClient::ERROR_VERSION      => __(
                'This version of Zabbix is too old: version 5.4 or later is required.',
                'grcmanager'
            ),
            ZabbixClient::ERROR_METHOD       => __('Refused: the connector only reads from Zabbix.', 'grcmanager'),
            'rights'                         => __('You are not allowed to do this.', 'grcmanager'),
            default                          => __('Zabbix returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Lit Zabbix et remplace la photographie précédente dans l'entité des lectures. Lecture complète
     * seulement : une lecture en échec ne remplace rien. $automatic : tâche automatique (sans
     * session, pas de contrôle de droits).
     *
     * @return array{error: string, hosts: int, problems: int, services: int, truncated: bool}
     */
    public static function read(?ZabbixClient $client = null, bool $automatic = false): array
    {
        $result = ['error' => '', 'hosts' => 0, 'problems' => 0, 'services' => 0, 'truncated' => false];
        if (!$automatic && !self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        $client ??= ZabbixConfig::client();
        $settings = ZabbixConfig::settings();
        $nowDate = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $now = (int) strtotime($nowDate);
        $fetch = $client->fetch($settings['period_days'], $now);
        $previous = ZabbixConfig::lastRead();
        if ($fetch['error'] !== '' || !$fetch['complete']) {
            $error = $fetch['error'] !== '' ? $fetch['error'] : ZabbixClient::ERROR_INVALID;
            // Le bilan garde la date et les nombres de la dernière lecture réussie.
            ZabbixConfig::saveLastRead(['error' => $error, 'failed_at' => $nowDate] + $previous);

            return ['error' => $error] + $result;
        }

        $hosts = ZabbixClient::hostRows($fetch['hosts']);
        $problems = ZabbixClient::problemRows($fetch['events'], $fetch['open'], $fetch['recoveries']);
        $services = ZabbixClient::serviceRows($fetch['slas']);
        self::store(ZabbixConfig::entity(), $hosts, $problems, $services, $nowDate);
        $result = [
            'error'     => '',
            'hosts'     => count($hosts),
            'problems'  => count(array_unique(array_column($problems, 'eventid'))),
            'services'  => count($services),
            'truncated' => $fetch['truncated'],
        ];
        ZabbixConfig::saveLastRead([
            'date'          => $nowDate,
            'version'       => $fetch['version'],
            'error'         => '',
            'hosts'         => $result['hosts'],
            'problems'      => $result['problems'],
            'services'      => $result['services'],
            'truncated'     => $fetch['truncated'],
            'sla_supported' => $fetch['sla_supported'],
        ]);
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'source'    => $automatic ? 'cron' : 'click',
            'hosts'     => $result['hosts'],
            'problems'  => $result['problems'],
            'services'  => $result['services'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Remplace la photographie de l'entité, en gardant les liens créés dans GLPI.
     *
     * @param list<array<string, mixed>> $hosts
     * @param list<array<string, mixed>> $problems
     * @param list<array<string, mixed>> $services
     */
    private static function store(int $entity, array $hosts, array $problems, array $services, string $now): void
    {
        global $DB;

        $links = [];
        foreach (
            $DB->request([
                'SELECT' => ['eventid', 'hostid', 'plugin_grcmanager_securityincidents_id', 'tickets_id'],
                'FROM'   => ZabbixTables::PROBLEMS,
                'WHERE'  => [
                    'entities_id' => $entity,
                    'OR'          => [
                        'plugin_grcmanager_securityincidents_id' => ['>', 0],
                        'tickets_id'                             => ['>', 0],
                    ],
                ],
            ]) as $row
        ) {
            $links[$row['eventid'] . '|' . $row['hostid']] = $row;
        }
        $processes = [];
        foreach (
            $DB->request([
                'SELECT' => ['serviceid', 'plugin_grcmanager_businessprocesses_id'],
                'FROM'   => ZabbixTables::SERVICES,
                'WHERE'  => ['entities_id' => $entity, 'plugin_grcmanager_businessprocesses_id' => ['>', 0]],
            ]) as $row
        ) {
            $processes[(string) $row['serviceid']] = (int) $row['plugin_grcmanager_businessprocesses_id'];
        }

        foreach ([ZabbixTables::HOSTS, ZabbixTables::PROBLEMS, ZabbixTables::SERVICES] as $table) {
            $DB->delete($table, ['entities_id' => $entity]);
        }
        foreach ($hosts as $host) {
            $DB->insert(ZabbixTables::HOSTS, [
                'entities_id'  => $entity,
                'is_recursive' => 1,
                'hostid'       => $host['hostid'],
                'host'         => $host['host'],
                'name'         => $host['name'],
                'ips'          => mb_substr(implode(',', $host['ips']), 0, 60000),
                'dns'          => mb_substr(implode(',', $host['dns']), 0, 60000),
                'serial'       => $host['serial'],
                'is_monitored' => $host['monitored'] ? 1 : 0,
                'availability' => mb_substr(implode(',', $host['availability']), 0, 64),
                'date_import'  => $now,
            ]);
        }
        foreach ($problems as $problem) {
            $link = $links[$problem['eventid'] . '|' . $problem['hostid']] ?? [];
            $DB->insert(ZabbixTables::PROBLEMS, [
                'entities_id'     => $entity,
                'is_recursive'    => 1,
                'eventid'         => $problem['eventid'],
                'hostid'          => $problem['hostid'],
                'name'            => $problem['name'],
                'severity'        => $problem['severity'],
                'date_problem'    => $problem['clock'] > 0 ? date('Y-m-d H:i:s', $problem['clock']) : null,
                'date_recovery'   => $problem['r_clock'] !== null ? date('Y-m-d H:i:s', $problem['r_clock']) : null,
                'is_open'         => $problem['is_open'] ? 1 : 0,
                'is_acknowledged' => $problem['acknowledged'] ? 1 : 0,
                'capacity'        => $problem['capacity'],
                'plugin_grcmanager_securityincidents_id'
                    => (int) ($link['plugin_grcmanager_securityincidents_id'] ?? 0),
                'tickets_id'      => (int) ($link['tickets_id'] ?? 0),
                'date_import'     => $now,
            ]);
        }
        foreach ($services as $service) {
            $DB->insert(ZabbixTables::SERVICES, [
                'entities_id'  => $entity,
                'is_recursive' => 1,
                'slaid'        => $service['slaid'],
                'sla_name'     => $service['sla_name'],
                'slo'          => $service['slo'],
                'serviceid'    => $service['serviceid'],
                'service_name' => $service['service_name'],
                'sli'          => $service['sli'],
                'sli_previous' => $service['sli_previous'],
                'downtime'     => $service['downtime'],
                'period_from'  => $service['period_from'] ? date('Y-m-d H:i:s', $service['period_from']) : null,
                'period_to'    => $service['period_to'] ? date('Y-m-d H:i:s', $service['period_to']) : null,
                'plugin_grcmanager_businessprocesses_id' => $processes[$service['serviceid']] ?? 0,
                'date_import'  => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Lignes des lectures visibles : entités de la session (et lectures récursives des entités
     * parentes), ou périmètre donné (KRI, tâche automatique).
     *
     * @param list<int>|null $entities
     * @return array<mixed>
     */
    private static function readingsWhere(string $table, ?array $entities): array
    {
        if ($entities === null) {
            return getEntitiesRestrictCriteria($table, '', '', true);
        }
        if ($entities === []) {
            return ['entities_id' => -1];
        }
        $ancestors = [];
        foreach ($entities as $entity) {
            foreach (getAncestorsOf('glpi_entities', $entity) as $ancestor) {
                $ancestors[(int) $ancestor] = (int) $ancestor;
            }
        }
        $or = [['entities_id' => $entities]];
        if ($ancestors !== []) {
            $or[] = ['entities_id' => array_values($ancestors), 'is_recursive' => 1];
        }

        return ['OR' => $or];
    }

    /**
     * Ordinateurs et matériels réseau des entités données, ou de la session.
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
                    : [$table . '.entities_id' => $entities === [] ? [-1] : $entities]);
            if ($excluded !== []) {
                $where[] = ['NOT' => ['states_id' => $excluded]];
            }
            $select = ['id', 'name', 'serial', 'entities_id'];
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
                    'serial'      => (string) $row['serial'],
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
     * @return array<string, bool> clé => actif critique
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
                $levels[$itemtype . ':' . (int) $row['items_id']] = $row;
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
        $critical = [];
        foreach (array_keys($assets) as $key) {
            $level = $levels[$key] ?? [];
            $critical[$key] = Rules::isCritical(
                (string) ($level['confidentiality'] ?? ''),
                (string) ($level['integrity'] ?? ''),
                (string) ($level['availability'] ?? ''),
                isset($exposed[$key])
            );
        }

        return $critical;
    }

    /**
     * Index de rapprochement : numéros de série uniques, noms complets et courts, adresses IP.
     *
     * @param array<string, array<string, mixed>> $assets
     * @return array{serials: array<string, string>, names: array<string, string>, ips: array<string, string>}
     */
    private static function lookup(array $assets): array
    {
        global $DB;

        $names = [];
        $serials = [];
        $duplicates = [];
        $byType = [];
        foreach ($assets as $key => $asset) {
            $name = mb_strtolower(trim((string) $asset['name']));
            if ($name !== '') {
                $names[$name] ??= $key;
                $names[Rules::normalizeHost($name)] ??= $key;
            }
            $serial = mb_strtolower(trim((string) $asset['serial']));
            if (mb_strlen($serial) >= 4 && !in_array($serial, self::GENERIC_SERIALS, true)) {
                if (isset($serials[$serial])) {
                    $duplicates[$serial] = true;
                }
                $serials[$serial] = $key;
            }
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        foreach (array_keys($duplicates) as $serial) {
            unset($serials[$serial]);
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

        return ['serials' => $serials, 'names' => $names, 'ips' => $ips];
    }

    /**
     * Processus métier qui dépendent des actifs (onglet « Dépendances » du processus), lisibles par
     * la personne ; aucun sans session.
     *
     * @param array<string, array<string, mixed>> $assets
     * @return array<string, list<array{id: int, name: string, rto_hours: int}>>
     */
    private static function processes(array $assets, bool $session): array
    {
        global $DB;

        if (!$session || !BusinessProcess::canView() || $assets === []) {
            return [];
        }
        $byType = [];
        foreach ($assets as $asset) {
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        $links = [];
        foreach ($byType as $itemtype => $ids) {
            foreach (
                $DB->request([
                    'SELECT'     => [
                        BusinessProcessItem::getTable() . '.items_id',
                        BusinessProcess::getTable() . '.id',
                        BusinessProcess::getTable() . '.name',
                        BusinessProcess::getTable() . '.rto_hours',
                    ],
                    'FROM'       => BusinessProcessItem::getTable(),
                    'INNER JOIN' => [
                        BusinessProcess::getTable() => ['ON' => [
                            BusinessProcessItem::getTable() => 'plugin_grcmanager_businessprocesses_id',
                            BusinessProcess::getTable()     => 'id',
                        ]],
                    ],
                    'WHERE'      => [
                        BusinessProcessItem::getTable() . '.itemtype' => $itemtype,
                        BusinessProcessItem::getTable() . '.items_id' => $ids,
                    ] + getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true),
                ]) as $row
            ) {
                $links[$itemtype . ':' . (int) $row['items_id']][] = [
                    'id'        => (int) $row['id'],
                    'name'      => (string) $row['name'],
                    'rto_hours' => (int) $row['rto_hours'],
                ];
            }
        }

        return $links;
    }

    /**
     * Calcul complet : actifs concernés et leur supervision, hôtes non rapprochés, problèmes visibles,
     * services. Sans session (KRI, tâche), passer le périmètre.
     *
     * @param list<int>|null $entities
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     problems: list<array<string, mixed>>, services: list<array<string, mixed>>, readings: bool}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $settings = ZabbixConfig::settings();
        $nowDate = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $now = (int) strtotime($nowDate);
        $from = $now - $settings['period_days'] * 86400;
        $session = $entities === null;
        $assets = self::assets($entities);
        $critical = self::criticality($assets);
        $lookup = self::lookup($assets);

        // Hôtes lus, rapprochés.
        $hosts = [];
        foreach (
            $DB->request([
                'FROM'  => ZabbixTables::HOSTS,
                'WHERE' => self::readingsWhere(ZabbixTables::HOSTS, $entities),
            ]) as $row
        ) {
            $ips = array_values(array_filter(explode(',', (string) $row['ips'])));
            $dns = array_values(array_filter(explode(',', (string) $row['dns'])));
            $key = Rules::match(
                (string) $row['serial'],
                [(string) $row['host'], (string) $row['name']],
                $ips,
                $dns,
                $lookup['serials'],
                $lookup['names'],
                $lookup['ips']
            );
            $hosts[(string) $row['hostid']] = [
                'hostid'       => (string) $row['hostid'],
                'host'         => (string) $row['host'],
                'name'         => (string) $row['name'] !== '' ? (string) $row['name'] : (string) $row['host'],
                'ips'          => $ips,
                'monitored'    => (bool) $row['is_monitored'],
                'availability' => array_map('intval', array_filter(
                    explode(',', (string) $row['availability']),
                    static fn (string $v): bool => $v !== ''
                )),
                'key'          => $key,
                'entities_id'  => (int) $row['entities_id'],
            ];
        }
        $readings = $hosts !== [];
        $byAsset = [];
        $unmatched = [];
        foreach ($hosts as $hostId => $host) {
            if ($host['key'] !== null) {
                $byAsset[$host['key']][] = $hostId;
            } elseif (
                $session
                    ? Session::haveAccessToEntity($host['entities_id'])
                    : in_array($host['entities_id'], $entities, true)
            ) {
                // Hôte non rapproché : seulement dans l'entité même des lectures, pas dans ses sous-entités.
                $unmatched[] = $host;
            }
        }
        $visibleHosts = array_fill_keys(array_column($unmatched, 'hostid'), true);
        foreach ($byAsset as $hostIds) {
            foreach ($hostIds as $hostId) {
                $visibleHosts[$hostId] = true;
            }
        }

        // Problèmes des hôtes visibles.
        $problems = [];
        $byHost = [];
        if ($visibleHosts !== []) {
            foreach (
                $DB->request([
                    'FROM'  => ZabbixTables::PROBLEMS,
                    'WHERE' => ['hostid' => array_map('strval', array_keys($visibleHosts))]
                        + self::readingsWhere(ZabbixTables::PROBLEMS, $entities),
                    'ORDER' => ['is_open DESC', 'severity DESC', 'date_problem DESC'],
                ]) as $row
            ) {
                $clock = $row['date_problem'] !== null ? (int) strtotime((string) $row['date_problem']) : 0;
                $recovered = $row['date_recovery'] !== null ? (int) strtotime((string) $row['date_recovery']) : null;
                $open = (bool) $row['is_open'];
                $host = $hosts[(string) $row['hostid']];
                $problem = [
                    'id'          => (int) $row['id'],
                    'eventid'     => (string) $row['eventid'],
                    'hostid'      => (string) $row['hostid'],
                    'host'        => $host['name'],
                    'asset_key'   => $host['key'],
                    'name'        => (string) $row['name'],
                    'severity'    => Rules::normalizeSeverity($row['severity']),
                    'high'        => Rules::isHigh(Rules::normalizeSeverity($row['severity'])),
                    'date'        => (string) $row['date_problem'],
                    'recovery'    => (string) ($row['date_recovery'] ?? ''),
                    'is_open'     => $open,
                    'acknowledged' => (bool) $row['is_acknowledged'],
                    'capacity'    => (string) $row['capacity'],
                    'duration'    => $open || $recovered !== null ? Rules::duration($clock, $recovered, $now) : null,
                    'clock'       => $clock,
                    'end'         => $open ? $now : ($recovered ?? $clock),
                    'incident_id' => (int) $row['plugin_grcmanager_securityincidents_id'],
                    'ticket_id'   => (int) $row['tickets_id'],
                ];
                $problems[] = $problem;
                $byHost[$problem['hostid']][] = $problem;
            }
        }

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
        unset($typeIds[0]);
        $typeNames = [];
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

        $concerned = [];
        foreach ($assets as $key => $asset) {
            $isNetwork = $asset['kind'] === 'network';
            $typeName = $typeNames[(int) $asset['type_id']] ?? '';
            $isServer = !$isNetwork
                && PatchLagRules::isServer($typeName, (string) ($systems[(int) $asset['id']]['name'] ?? ''));
            $assetHosts = $byAsset[$key] ?? [];
            if (!Rules::isConcerned($settings['scope'], $critical[$key], $isServer, $isNetwork) && $assetHosts === []) {
                continue;
            }
            $concerned[$key] = $asset + ['is_server' => $isServer, 'type' => $isNetwork ? '' : $typeName];
        }
        $processes = self::processes($concerned, $session);

        $entityNames = [];
        $rows = [];
        foreach ($concerned as $key => $asset) {
            $assetHosts = $byAsset[$key] ?? [];
            $monitored = false;
            $states = [];
            $hostNames = [];
            $intervals = [];
            $open = ['high' => 0, 'other' => 0];
            $longest = 0;
            $capacity = [];
            $periodProblems = 0;
            foreach ($assetHosts as $hostId) {
                $host = $hosts[$hostId];
                $monitored = $monitored || $host['monitored'];
                $states = array_merge($states, $host['availability']);
                $hostNames[] = $host['name'];
                foreach ($byHost[$hostId] ?? [] as $problem) {
                    if ($problem['is_open']) {
                        $open[$problem['high'] ? 'high' : 'other']++;
                    }
                    if ($problem['end'] >= $from) {
                        $periodProblems++;
                        if ($problem['capacity'] !== '') {
                            $capacity[$problem['capacity']] = ($capacity[$problem['capacity']] ?? 0) + 1;
                        }
                    }
                    if ($problem['high'] && $problem['duration'] !== null) {
                        $intervals[] = [$problem['clock'], $problem['end']];
                        if ($problem['end'] >= $from) {
                            $longest = max($longest, (int) $problem['duration']);
                        }
                    }
                }
            }
            $status = Rules::status($assetHosts !== [], $monitored, $states);
            $linked = [];
            $rtoGap = false;
            foreach ($processes[$key] ?? [] as $process) {
                $exceeded = Rules::exceedsRto($longest, $process['rto_hours']);
                $rtoGap = $rtoGap || $exceeded;
                $linked[] = $process + [
                    'url'      => BusinessProcess::getFormURLWithID($process['id']),
                    'exceeded' => $exceeded,
                ];
            }
            $entityId = (int) $asset['entities_id'];
            $entityNames[$entityId] ??= $session ? (string) Dropdown::getDropdownName('glpi_entities', $entityId) : '';
            $itemtype = (string) $asset['itemtype'];
            $name = (string) $asset['name'];
            $rows[] = [
                'key'          => $key,
                'itemtype'     => $itemtype,
                'id'           => (int) $asset['id'],
                'name'         => $name !== ''
                    ? $name : sprintf('%s %d', $itemtype::getTypeName(1), (int) $asset['id']),
                'url'          => $session ? $itemtype::getFormURLWithID((int) $asset['id']) : '',
                'kind'         => $asset['kind'],
                'type'         => $asset['kind'] === 'network' ? NetworkEquipment::getTypeName(1) : $asset['type'],
                'entity'       => $entityNames[$entityId],
                'entities_id'  => $entityId,
                'is_server'    => $asset['is_server'],
                'critical'     => $critical[$key],
                'status'       => $status,
                'hosts'        => $hostNames,
                'availability' => Rules::isCovered($status) ? Rules::availability($intervals, $from, $now) : null,
                'longest'      => $longest,
                'open_high'    => $open['high'],
                'open_other'   => $open['other'],
                'problems'     => $periodProblems,
                'capacity'     => $capacity,
                'processes'    => $linked,
                'rto_gap'      => $rtoGap,
            ];
        }

        // Disponibilité des services (SLA).
        $services = [];
        foreach (
            $DB->request([
                'FROM'  => ZabbixTables::SERVICES,
                'WHERE' => self::readingsWhere(ZabbixTables::SERVICES, $entities),
                'ORDER' => ['sla_name', 'service_name'],
            ]) as $row
        ) {
            $sli = $row['sli'] !== null ? (float) $row['sli'] : null;
            $slo = $row['slo'] !== null ? (float) $row['slo'] : null;
            $services[] = [
                'id'           => (int) $row['id'],
                'sla'          => (string) $row['sla_name'],
                'service'      => (string) $row['service_name'],
                'slo'          => $slo,
                'sli'          => $sli,
                'sli_previous' => $row['sli_previous'] !== null ? (float) $row['sli_previous'] : null,
                'downtime'     => (int) $row['downtime'],
                'status'       => Rules::sliStatus($sli, $slo),
                'period_from'  => (string) ($row['period_from'] ?? ''),
                'process_id'   => (int) $row['plugin_grcmanager_businessprocesses_id'],
                'entities_id'  => (int) $row['entities_id'],
            ];
        }
        $readings = $readings || $services !== [];

        return [
            'assets'   => $rows,
            'unmatched' => $unmatched,
            'problems' => $problems,
            'services' => $services,
            'readings' => $readings,
        ];
    }

    /**
     * @return array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
     *     problems: list<array<string, mixed>>, services: list<array<string, mixed>>, readings: bool}
     */
    private static function data(): array
    {
        if (!self::canView()) {
            return ['assets' => [], 'unmatched' => [], 'problems' => [], 'services' => [], 'readings' => false];
        }
        /** @var array{assets: list<array<string, mixed>>, unmatched: list<array<string, mixed>>,
         *     problems: list<array<string, mixed>>, services: list<array<string, mixed>>, readings: bool} $memo */
        $memo = self::$memo ??= self::compute(null);

        return $memo;
    }

    /**
     * Synthèse : couverture des actifs concernés et des actifs critiques, problèmes, capacité, services.
     *
     * @param array{assets: list<array<string, mixed>>, problems: list<array<string, mixed>>,
     *     services: list<array<string, mixed>>}|null $data
     * @return array<string, mixed>
     */
    public static function summary(?array $data = null): array
    {
        $data ??= self::data();
        $settings = ZabbixConfig::settings();
        $now = (int) strtotime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $from = $now - $settings['period_days'] * 86400;
        $assets = $data['assets'];
        $critical = array_values(array_filter($assets, static fn (array $row): bool => (bool) $row['critical']));
        $events = [];
        $openHigh = [];
        $resolvedHigh = [];
        $capacity = array_fill_keys(Rules::CAPACITIES, 0);
        $capacityAssets = [];
        foreach ($data['problems'] as $problem) {
            if ($problem['end'] < $from && !$problem['is_open']) {
                continue;
            }
            $events[$problem['eventid']] = true;
            if ($problem['high']) {
                if ($problem['is_open']) {
                    $openHigh[$problem['eventid']] = true;
                } else {
                    $resolvedHigh[$problem['eventid']] = true;
                }
            }
            if ($problem['capacity'] !== '' && isset($capacity[$problem['capacity']])) {
                $capacity[$problem['capacity']]++;
                if ($problem['asset_key'] !== null) {
                    $capacityAssets[$problem['asset_key']] = true;
                }
            }
        }
        $availabilities = array_values(array_filter(
            array_column($data['services'], 'sli'),
            static fn ($value): bool => $value !== null
        ));

        return [
            'concerned'       => Rules::summary($assets),
            'critical'        => Rules::summary($critical),
            'problems'        => count($events),
            'open_high'       => count($openHigh),
            'resolved_high'   => count($resolvedHigh),
            'capacity'        => $capacity,
            'capacity_assets' => count($capacityAssets),
            'rto_gaps'        => count(array_filter($assets, static fn (array $row): bool => (bool) $row['rto_gap'])),
            'services'        => count($data['services']),
            'services_below'  => count(array_filter(
                $data['services'],
                static fn (array $row): bool => $row['status'] === Rules::SLI_BELOW
            )),
            'lowest_sli'      => $availabilities === [] ? null : min($availabilities),
            'period_days'     => $settings['period_days'],
        ];
    }

    // ------------------------------------------------------------------ Indicateurs (KRI).

    /**
     * KRI « disponibilité du mois (%) » : plus faible disponibilité des services sur la période en
     * cours (SLA Zabbix), null sans service lu.
     *
     * @param list<int> $perimeter
     */
    public static function lowestServiceAvailability(array $perimeter): ?float
    {
        $value = self::summary(self::compute($perimeter))['lowest_sli'];

        return $value === null ? null : (float) $value;
    }

    /**
     * KRI « problèmes ouverts de gravité haute » sur les actifs du périmètre, null sans lecture.
     *
     * @param list<int> $perimeter
     */
    public static function openHighProblems(array $perimeter): ?float
    {
        $data = self::compute($perimeter);

        return $data['readings'] ? (float) self::summary($data)['open_high'] : null;
    }

    /**
     * KRI « actifs critiques non supervisés », null sans lecture.
     *
     * @param list<int> $perimeter
     */
    public static function unmonitoredCriticalAssets(array $perimeter): ?float
    {
        $data = self::compute($perimeter);

        return $data['readings'] ? (float) self::summary($data)['critical']['uncovered'] : null;
    }

    // ------------------------------------------------------------------ Actions sur clic.

    /**
     * Problème visible par la personne (ligne de la page), ou null.
     *
     * @return array<string, mixed>|null
     */
    private static function visibleProblem(int $id): ?array
    {
        foreach (self::data()['problems'] as $problem) {
            if ($problem['id'] === $id) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Actif rapproché d'un problème, s'il est visible.
     *
     * @param array<string, mixed> $problem
     * @return array<string, mixed>|null
     */
    private static function assetOf(array $problem): ?array
    {
        foreach (self::data()['assets'] as $asset) {
            if ($asset['key'] === $problem['asset_key']) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Texte de l'incident ou du ticket proposé (texte brut).
     *
     * @param array<string, mixed> $problem
     */
    private static function problemText(array $problem): string
    {
        $severity = self::severityInfos()[$problem['severity']]['label'] ?? '';

        return implode("\n", [
            sprintf(__('Problem reported by Zabbix: %s', 'grcmanager'), $problem['name']),
            sprintf(__('Host: %s', 'grcmanager'), $problem['host']),
            sprintf(__('Severity: %s', 'grcmanager'), $severity),
            sprintf(__('Started on: %s', 'grcmanager'), Html::convDateTime($problem['date'])),
            sprintf(__('Zabbix event: %s', 'grcmanager'), $problem['eventid']),
        ]);
    }

    /**
     * Crée, sur clic, un incident de sécurité à partir d'un problème de gravité haute (une seule fois
     * par problème), rattaché à l'actif. Renvoie l'identifiant de l'incident, 0 sinon.
     */
    public static function proposeIncident(int $problemId): int
    {
        global $DB;

        if (!self::canView() || !PluginGrcmanagerSecurityIncident::canCreate()) {
            return 0;
        }
        $problem = self::visibleProblem($problemId);
        if ($problem === null || !$problem['high'] || $problem['incident_id'] > 0) {
            return 0;
        }
        $asset = self::assetOf($problem);
        $entity = $asset !== null && Session::haveAccessToEntity((int) $asset['entities_id'])
            ? (int) $asset['entities_id'] : (int) Session::getActiveEntity();
        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) $incident->add([
            'name'        => mb_substr(sprintf('Zabbix: %s (%s)', $problem['name'], $problem['host']), 0, 250),
            'content'     => self::problemText($problem),
            'date'        => $problem['date'] !== ''
                ? $problem['date'] : ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            'severity'    => Rules::incidentSeverity($problem['severity']),
            'category'    => 'availability',
            'source'      => 'monitoring',
            'external_id' => mb_substr('zabbix:' . $problem['eventid'], 0, 255),
            'entities_id' => $entity,
        ]);
        if ($incidentId <= 0) {
            return 0;
        }
        if ($asset !== null) {
            (new PluginGrcmanagerSecurityIncident_Item())->add([
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'itemtype' => $asset['itemtype'],
                'items_id' => $asset['id'],
            ]);
        }
        $DB->update(
            ZabbixTables::PROBLEMS,
            ['plugin_grcmanager_securityincidents_id' => $incidentId],
            ['eventid' => $problem['eventid']]
        );
        AuditLog::record('create', self::class, 0, $incidentId, 'success', ['eventid' => $problem['eventid']]);
        self::reset();

        return $incidentId;
    }

    /**
     * Crée, sur clic, un ticket d'incident à partir d'un problème de gravité haute (une seule fois
     * par problème), avec l'actif en élément associé. Renvoie l'identifiant du ticket, 0 sinon.
     */
    public static function createTicket(int $problemId): int
    {
        global $DB;

        if (!self::canView() || !(bool) Ticket::canCreate()) {
            return 0;
        }
        $problem = self::visibleProblem($problemId);
        if ($problem === null || !$problem['high'] || $problem['ticket_id'] > 0) {
            return 0;
        }
        $asset = self::assetOf($problem);
        $entity = $asset !== null && Session::haveAccessToEntity((int) $asset['entities_id'])
            ? (int) $asset['entities_id'] : (int) Session::getActiveEntity();
        $input = [
            'name'        => mb_substr(sprintf('Zabbix: %s (%s)', $problem['name'], $problem['host']), 0, 250),
            'content'     => nl2br(htmlescape(self::problemText($problem))),
            'type'        => Ticket::INCIDENT_TYPE,
            'entities_id' => $entity,
        ];
        if ($asset !== null) {
            $input['items_id'] = [$asset['itemtype'] => [$asset['id']]];
        }
        $ticketId = (int) (new Ticket())->add($input);
        if ($ticketId <= 0) {
            return 0;
        }
        $DB->update(ZabbixTables::PROBLEMS, ['tickets_id' => $ticketId], ['eventid' => $problem['eventid']]);
        AuditLog::record('create', self::class, 0, 0, 'success', [
            'eventid' => $problem['eventid'], 'tickets_id' => $ticketId,
        ]);
        self::reset();

        return $ticketId;
    }

    /**
     * Rattache un service Zabbix (ligne visible) à un processus métier lisible, ou l'en détache (0).
     */
    public static function linkService(int $serviceRowId, int $processId): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $visible = in_array($serviceRowId, array_column(self::data()['services'], 'id'), true);
        if (!$visible) {
            return false;
        }
        if ($processId > 0) {
            $process = new BusinessProcess();
            if (!$process->getFromDB($processId) || !$process->canViewItem()) {
                return false;
            }
        }
        $ok = $DB->update(
            ZabbixTables::SERVICES,
            ['plugin_grcmanager_businessprocesses_id' => max(0, $processId)],
            ['id' => $serviceRowId]
        );
        self::reset();

        return (bool) $ok;
    }

    /**
     * Efface les lectures de l'entité des lectures (les liens vers les incidents et tickets créés
     * restent dans GLPI).
     */
    public static function clear(): bool
    {
        global $DB;

        if (!self::canManage() || !Session::haveAccessToEntity(ZabbixConfig::entity())) {
            return false;
        }
        foreach ([ZabbixTables::HOSTS, ZabbixTables::PROBLEMS, ZabbixTables::SERVICES] as $table) {
            $DB->delete($table, ['entities_id' => ZabbixConfig::entity()]);
        }
        AuditLog::record('purge', self::class, 0, 0, 'success', []);
        self::reset();

        return true;
    }

    // ------------------------------------------------------------------ Tâche automatique.

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === self::CRON_NAME
            ? ['description' => __('Zabbix: reads hosts, problems and service availability (read only)', 'grcmanager')]
            : [];
    }

    public static function cronZabbix(CronTask $task): int
    {
        if (!ZabbixConfig::isEnabled() || !ZabbixConfig::isConfigured()) {
            return 0;
        }
        $result = self::read(null, true);
        if ($result['error'] !== '') {
            // Code d'erreur seulement : ni jeton, ni adresse complète.
            $task->log('Zabbix: ' . $result['error']);

            return 0;
        }
        $task->addVolume($result['hosts']);

        return 1;
    }

    /**
     * La tâche automatique suit l'interrupteur du connecteur (désactivée par défaut).
     */
    public static function syncCronState(bool $enabled): void
    {
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, self::CRON_NAME)) {
            $state = $enabled ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
            $current = (int) $task->fields['state'];
            if ($current !== $state && !($enabled && $current === CronTask::STATE_RUNNING)) {
                $task->update(['id' => $task->getID(), 'state' => $state]);
            }
        }
    }

    // ------------------------------------------------------------------ Page.

    /** Durée lisible (« 2 heures 5 minutes »), vide si inconnue. */
    private static function durationLabel(?int $seconds): string
    {
        return $seconds === null ? '' : (string) Html::timestampToString(max(0, $seconds), false);
    }

    /**
     * Libellés d'affichage d'un actif : plus long arrêt, processus.
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private static function decorateAsset(array $asset): array
    {
        $asset['longest_label'] = $asset['longest'] > 0 ? self::durationLabel((int) $asset['longest']) : '';
        $durations = BusinessProcess::getDurationOptions();
        foreach ($asset['processes'] as &$process) {
            $process['rto'] = $durations[$process['rto_hours']] ?? '';
        }
        unset($process);

        return $asset;
    }

    /**
     * Libellés d'affichage d'un problème : dates et durée.
     *
     * @param array<string, mixed> $problem
     * @return array<string, mixed>
     */
    private static function decorateProblem(array $problem): array
    {
        $problem['date_label'] = $problem['date'] !== '' ? (string) Html::convDateTime($problem['date']) : '';
        $problem['recovery_label'] = $problem['recovery'] !== ''
            ? (string) Html::convDateTime($problem['recovery']) : '';
        $problem['duration_label'] = self::durationLabel($problem['duration']);

        return $problem;
    }

    /**
     * Processus métier lisibles, pour le rattachement d'un service.
     *
     * @return array<int, string>
     */
    private static function processChoices(): array
    {
        global $DB;

        if (!BusinessProcess::canView()) {
            return [];
        }
        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'rto_hours'],
                'FROM'   => BusinessProcess::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true),
                'ORDER'  => ['name'],
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $data = self::data();
        $summary = self::summary($data);
        $statusRank = array_flip(Rules::STATUSES);
        $assets = $data['assets'];
        usort(
            $assets,
            static fn (array $a, array $b): int => [
                $statusRank[$a['status']], !$a['critical'], !$a['rto_gap'], -$a['open_high'], $a['name'],
            ] <=> [
                $statusRank[$b['status']], !$b['critical'], !$b['rto_gap'], -$b['open_high'], $b['name'],
            ]
        );
        $assetUrls = [];
        foreach ($data['assets'] as $asset) {
            $assetUrls[$asset['key']] = ['name' => $asset['name'], 'url' => $asset['url']];
        }
        $settings = ZabbixConfig::settings();
        $from = (int) strtotime($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))
            - $settings['period_days'] * 86400;
        // Un problème par événement (plusieurs hôtes : la première ligne), ouverts puis gravité.
        $problems = [];
        foreach ($data['problems'] as $problem) {
            if (isset($problems[$problem['eventid']]) || ($problem['end'] < $from && !$problem['is_open'])) {
                continue;
            }
            $problems[$problem['eventid']] = self::decorateProblem($problem) + [
                'asset'        => $problem['asset_key'] !== null ? ($assetUrls[$problem['asset_key']] ?? null) : null,
                'incident_url' => $problem['incident_id'] > 0
                    ? PluginGrcmanagerSecurityIncident::getFormURLWithID($problem['incident_id']) : '',
                'ticket_url'   => $problem['ticket_id'] > 0 ? Ticket::getFormURLWithID($problem['ticket_id']) : '',
            ];
        }
        $processes = self::processChoices();
        $services = [];
        foreach ($data['services'] as $service) {
            $services[] = $service + [
                'downtime_label' => self::durationLabel($service['downtime']),
                'process'     => $processes[$service['process_id']] ?? '',
                'process_url' => $service['process_id'] > 0 && isset($processes[$service['process_id']])
                    ? BusinessProcess::getFormURLWithID($service['process_id']) : '',
            ];
        }
        $lastRead = ZabbixConfig::lastRead();

        return [
            'summary'         => $summary,
            'statuses'        => self::statusInfos(),
            'severities'      => self::severityInfos(),
            'capacities'      => self::capacityInfos(),
            'rows'            => array_map(self::decorateAsset(...), array_slice($assets, 0, self::MAX_ROWS)),
            'total_rows'      => count($assets),
            'problems'        => array_slice(array_values($problems), 0, self::MAX_ROWS),
            'total_problems'  => count($problems),
            'unmatched'       => array_slice($data['unmatched'], 0, self::MAX_ROWS),
            'total_unmatched' => count($data['unmatched']),
            'services'        => $services,
            'process_choices' => $processes,
            'readings'        => $data['readings'],
            'settings'        => $settings,
            'scopes'          => self::scopeLabels(),
            // Le jeton n'est jamais renvoyé, seulement sa présence.
            'connection'      => [
                'url'        => ZabbixConfig::rawUrl(),
                'has_token'  => ZabbixConfig::hasToken(),
                'configured' => ZabbixConfig::isConfigured(),
                'entity'     => ZabbixConfig::entity(),
                'entity_name' => (string) Dropdown::getDropdownName('glpi_entities', ZabbixConfig::entity()),
                'enabled'    => ZabbixConfig::isEnabled(),
                'allow_private' => \GlpiPlugin\Grcmanager\Compat\Http::allowsPrivateNetworks(),
            ],
            'last_read'       => $lastRead + [
                'date_label'  => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
                'error_label' => $lastRead['error'] !== '' ? self::errorMessage($lastRead['error']) : '',
            ],
            'can_manage'      => self::canManage(),
            'can_incident'    => (bool) PluginGrcmanagerSecurityIncident::canCreate(),
            'can_ticket'      => (bool) Ticket::canCreate(),
            'action'          => self::getSearchURL(),
            // Issue #1042 : la connexion se règle dans l'onglet Connecteurs de la configuration.
            'connector_url'   => \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl(
                \GlpiPlugin\Grcmanager\Config\Block\ZabbixConnectorBlock::ANCHOR
            ),
            'processes_url'   => BusinessProcess::getSearchURL(),
            'audit_url'       => ComplianceAudit::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/zabbix_monitoring.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Onglets sur l'actif et le processus.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !self::canView()) {
            return '';
        }
        if (!in_array($item::class, [Computer::class, NetworkEquipment::class, BusinessProcess::class], true)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
    }

    /**
     * Actifs (ordinateur, matériel réseau) d'un élément : lui-même, ou les dépendances d'un processus.
     *
     * @return list<string> clés « type:id »
     */
    private static function keysFor(CommonDBTM $item): array
    {
        if ($item instanceof BusinessProcess) {
            $keys = [];
            foreach (BusinessProcessItem::getForProcess((int) $item->getID()) as $link) {
                if (in_array((string) $link['itemtype'], [Computer::class, NetworkEquipment::class], true)) {
                    $keys[] = $link['itemtype'] . ':' . (int) $link['items_id'];
                }
            }

            return $keys;
        }

        return [$item::class . ':' . (int) $item->getID()];
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        if (!in_array($item::class, [Computer::class, NetworkEquipment::class, BusinessProcess::class], true)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/zabbix_item_tab.html.twig', self::tabData($item));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tabData(CommonDBTM $item): array
    {
        $data = self::data();
        $keys = array_fill_keys(self::keysFor($item), true);
        $assets = array_values(array_filter(
            $data['assets'],
            static fn (array $row): bool => isset($keys[$row['key']])
        ));
        $problems = [];
        foreach ($data['problems'] as $problem) {
            if (
                $problem['asset_key'] !== null && isset($keys[$problem['asset_key']])
                && !isset($problems[$problem['eventid']])
            ) {
                $problems[$problem['eventid']] = self::decorateProblem($problem);
            }
        }
        $services = [];
        if ($item instanceof BusinessProcess) {
            $services = array_values(array_filter(
                $data['services'],
                static fn (array $row): bool => $row['process_id'] === (int) $item->getID()
            ));
        }

        return [
            'is_process'  => $item instanceof BusinessProcess,
            'readings'    => $data['readings'],
            'assets'      => array_map(self::decorateAsset(...), $assets),
            'problems'    => array_slice(array_values($problems), 0, 50),
            'services'    => array_map(
                static fn (array $row): array => $row + ['downtime_label' => self::durationLabel($row['downtime'])],
                $services
            ),
            'statuses'    => self::statusInfos(),
            'severities'  => self::severityInfos(),
            'capacities'  => self::capacityInfos(),
            'period_days' => ZabbixConfig::settings()['period_days'],
            'page_url'    => self::getSearchURL(),
        ];
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.8.16 (couverture, problèmes traités), A.8.6 (saturations) et A.5.30
     * (disponibilité, RTO). Null si la mesure n'est pas concernée, sans les droits ou sans lecture.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.16', 'A.8.6', 'A.5.30'], true) || !self::canView()) {
            return null;
        }
        $data = self::data();
        if (!$data['readings']) {
            return null;
        }
        $summary = self::summary($data);
        $lastRead = ZabbixConfig::lastRead();

        return [
            'code'            => $code,
            'rate'            => $summary['concerned']['rate'],
            'concerned'       => (int) $summary['concerned']['total'],
            'critical_uncovered' => (int) $summary['critical']['uncovered'],
            'open_high'       => $summary['open_high'],
            'resolved_high'   => $summary['resolved_high'],
            'problems'        => $summary['problems'],
            'capacity_assets' => $summary['capacity_assets'],
            'capacity'        => array_sum($summary['capacity']),
            'lowest_sli'      => $summary['lowest_sli'],
            'services_below'  => $summary['services_below'],
            'rto_gaps'        => $summary['rto_gaps'],
            'period_days'     => $summary['period_days'],
            'date'            => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
            'url'             => self::getSearchURL(),
        ];
    }
}

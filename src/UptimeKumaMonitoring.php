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

use Appliance;
use CommonDBTM;
use CommonGLPI;
use Computer;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\UptimeKumaTables;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaClient;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules as Rules;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixRules;
use Html;
use NetworkEquipment;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Session;

/**
 * Issue #842 : disponibilité des services exposés et certificats TLS lus dans Uptime Kuma, en
 * LECTURE SEULE, pour les mesures A.8.16 (activités de surveillance), A.5.30 (préparation des TIC à
 * la continuité) et A.8.24 (cryptographie : certificats).
 *
 * - Lecture sur clic (ou par la tâche automatique horaire, désactivée par défaut) de l'endpoint
 *   Prometheus « /metrics » (clé d'API) et des pages de statut publiques choisies. Une lecture en
 *   échec ne remplace pas la précédente. Chaque lecture ajoute un relevé d'état par sonde : la
 *   disponibilité et la plus longue panne de la période sont calculées sur ces relevés.
 * - Sondes rapprochées des ordinateurs, matériels réseau et applications (Appliance) de GLPI par le
 *   nom d'hôte surveillé ou le nom de la sonde ; sondes sans actif, et actifs publiés sur Internet
 *   (matrice des flux) sans sonde, signalés.
 * - Plus longue panne comparée au RTO des processus métier qui dépendent de l'actif (ou choisis pour
 *   la sonde), avec leur criticité.
 * - Certificats TLS : jours restants relevés par Uptime Kuma, alerte avant échéance (seuil réglable,
 *   KRI et notification des alertes de KRI) ; aucun renouvellement n'est fait.
 * - Une panne longue peut être proposée comme incident de sécurité, sur clic seulement ; l'incident
 *   ouvre ensuite la communication de crise (#616) pour l'annonce aux utilisateurs.
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour la connexion, la lecture, les réglages et le rattachement à un
 * processus. Rien n'est modifié dans Uptime Kuma ; dans GLPI, seul l'incident demandé est créé.
 */
class UptimeKumaMonitoring extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const CRON_NAME = 'uptimekuma';

    /** Sondes et actifs listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** Relevés gardés au plus (jours). */
    public const KEEP_DAYS = Rules::MAX_PERIOD_DAYS;

    /** @var array<string, mixed>|null calcul de la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Exposed services (Uptime Kuma)', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-world-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/uptimekuma.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ)
            && (bool) Session::haveRight(Computer::$rightname, READ);
    }

    /**
     * Connexion, lecture, réglages et rattachement d'une sonde à un processus métier.
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
     * @return array<int, array{label: string, color: string, icon: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_UP          => [
                'label' => __('Up', 'grcmanager'), 'color' => 'success', 'icon' => 'ti ti-circle-check',
            ],
            Rules::STATUS_DOWN        => [
                'label' => __('Down', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-circle-x',
            ],
            Rules::STATUS_PENDING     => [
                'label' => __('Pending', 'grcmanager'), 'color' => 'warning', 'icon' => 'ti ti-hourglass',
            ],
            Rules::STATUS_MAINTENANCE => [
                'label' => __('Under maintenance', 'grcmanager'), 'color' => 'blue', 'icon' => 'ti ti-tool',
            ],
            Rules::STATUS_UNKNOWN     => [
                'label' => __('Unknown', 'grcmanager'), 'color' => 'secondary', 'icon' => 'ti ti-help-circle',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function certInfos(): array
    {
        return [
            Rules::CERT_EXPIRED  => [
                'label' => __('Expired', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-lock-x',
            ],
            Rules::CERT_INVALID  => [
                'label' => __('Not valid', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-lock-exclamation',
            ],
            Rules::CERT_EXPIRING => [
                'label' => __('Expires soon', 'grcmanager'), 'color' => 'warning', 'icon' => 'ti ti-clock-exclamation',
            ],
            Rules::CERT_OK       => [
                'label' => __('Valid', 'grcmanager'), 'color' => 'success', 'icon' => 'ti ti-lock-check',
            ],
            Rules::CERT_NONE     => [
                'label' => __('No certificate read', 'grcmanager'), 'color' => 'secondary', 'icon' => 'ti ti-lock-off',
            ],
        ];
    }

    /**
     * Message d'une erreur de lecture (jamais la clé d'API).
     */
    public static function errorMessage(string $code, string $page = ''): string
    {
        return match ($code) {
            UptimeKumaClient::ERROR_CONFIG       => __(
                'Enter the address of Uptime Kuma, then an API key or at least one status page.',
                'grcmanager'
            ),
            UptimeKumaClient::ERROR_UNAUTHORIZED => __(
                'Uptime Kuma refused the API key: check that it is active and not expired (Settings > API Keys).',
                'grcmanager'
            ),
            UptimeKumaClient::ERROR_PAGE         => sprintf(
                __(
                    'The status page "%s" does not exist in Uptime Kuma: check its identifier (end of its address).',
                    'grcmanager'
                ),
                $page
            ),
            UptimeKumaClient::ERROR_INTERNAL     => __(
                'Uptime Kuma has an internal address, refused by default: allow internal addresses in the plugin '
                    . 'configuration page (Outgoing connections).',
                'grcmanager'
            ),
            UptimeKumaClient::ERROR_UNREACHABLE  => __(
                'Uptime Kuma could not be reached: check the address, the network access or the GLPI proxy.',
                'grcmanager'
            ),
            UptimeKumaClient::ERROR_REFUSED      => __(
                'Refused: the connector only reads from Uptime Kuma.',
                'grcmanager'
            ),
            'rights'                             => __('You are not allowed to do this.', 'grcmanager'),
            default                              => __('Uptime Kuma returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Lit Uptime Kuma : remplace la photographie des sondes de l'entité des lectures et ajoute les
     * relevés. Lecture complète seulement : une lecture en échec ne remplace rien. $automatic :
     * tâche automatique (sans session, pas de contrôle de droits).
     *
     * @return array{error: string, page: string, monitors: int, samples: int, pages: int, truncated: bool}
     */
    public static function read(?UptimeKumaClient $client = null, bool $automatic = false): array
    {
        $result = ['error' => '', 'page' => '', 'monitors' => 0, 'samples' => 0, 'pages' => 0, 'truncated' => false];
        if (!$automatic && !self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        $client ??= UptimeKumaConfig::client();
        $nowDate = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $now = (int) strtotime($nowDate);
        $fetch = $client->fetch(UptimeKumaConfig::statusPages(), $now);
        $previous = UptimeKumaConfig::lastRead();
        if ($fetch['error'] !== '' || !$fetch['complete']) {
            $error = $fetch['error'] !== '' ? $fetch['error'] : UptimeKumaClient::ERROR_INVALID;
            // Le bilan garde la date et les nombres de la dernière lecture réussie.
            UptimeKumaConfig::saveLastRead(
                ['error' => $error, 'page' => $fetch['page'], 'failed_at' => $nowDate] + $previous
            );

            return ['error' => $error, 'page' => $fetch['page']] + $result;
        }

        $added = self::store(UptimeKumaConfig::entity(), $fetch['monitors'], $fetch['samples'], $nowDate, $now);
        $result = [
            'error'     => '',
            'page'      => '',
            'monitors'  => count($fetch['monitors']),
            'samples'   => $added,
            'pages'     => count($fetch['pages']),
            'truncated' => $fetch['truncated'],
        ];
        UptimeKumaConfig::saveLastRead([
            'date'      => $nowDate,
            'error'     => '',
            'page'      => '',
            'monitors'  => $result['monitors'],
            'samples'   => $added,
            'pages'     => $result['pages'],
            'metrics'   => $fetch['metrics'],
            'truncated' => $fetch['truncated'],
        ]);
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'source'   => $automatic ? 'cron' : 'click',
            'monitors' => $result['monitors'],
            'samples'  => $added,
        ]);
        self::reset();

        return $result;
    }

    /**
     * Remplace les sondes de l'entité, ajoute les relevés nouveaux (un relevé déjà connu, à la même
     * heure, n'est pas répété) et purge ceux de plus de KEEP_DAYS jours. Renvoie le nombre ajouté.
     *
     * @param list<array<string, mixed>> $monitors
     * @param list<array{key: string, time: int, status: int, ping: ?int}> $samples
     */
    private static function store(int $entity, array $monitors, array $samples, string $nowDate, int $now): int
    {
        global $DB;

        $DB->delete(UptimeKumaTables::MONITORS, ['entities_id' => $entity]);
        foreach ($monitors as $monitor) {
            $DB->insert(UptimeKumaTables::MONITORS, [
                'entities_id'   => $entity,
                'is_recursive'  => 1,
                'monitor_key'   => $monitor['key'],
                'monitor_id'    => $monitor['monitor_id'],
                'name'          => $monitor['name'],
                'type'          => $monitor['type'],
                'url'           => $monitor['url'],
                'hostname'      => $monitor['hostname'],
                'port'          => $monitor['port'],
                'status'        => $monitor['status'],
                'response_time' => $monitor['response_time'],
                'cert_days'     => $monitor['cert_days'],
                'cert_valid'    => $monitor['cert_valid'] === null ? null : ($monitor['cert_valid'] ? 1 : 0),
                'uptime_24h'    => $monitor['uptime_24h'],
                'status_pages'  => mb_substr(implode(',', (array) $monitor['pages']), 0, 255),
                'date_import'   => $nowDate,
            ]);
        }

        $DB->delete(UptimeKumaTables::SAMPLES, [
            'entities_id' => $entity,
            'date_sample' => ['<', date('Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400)],
        ]);
        $oldest = $now;
        foreach ($samples as $sample) {
            $oldest = min($oldest, $sample['time']);
        }
        $known = [];
        if ($samples !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['monitor_key', 'date_sample'],
                    'FROM'   => UptimeKumaTables::SAMPLES,
                    'WHERE'  => [
                        'entities_id' => $entity,
                        'date_sample' => ['>=', date('Y-m-d H:i:s', $oldest)],
                    ],
                ]) as $row
            ) {
                $known[$row['monitor_key'] . '|' . $row['date_sample']] = true;
            }
        }
        $added = 0;
        foreach ($samples as $sample) {
            if ($sample['time'] < $now - self::KEEP_DAYS * 86400) {
                continue;
            }
            $date = date('Y-m-d H:i:s', $sample['time']);
            if (isset($known[$sample['key'] . '|' . $date])) {
                continue;
            }
            $known[$sample['key'] . '|' . $date] = true;
            $DB->insert(UptimeKumaTables::SAMPLES, [
                'entities_id'   => $entity,
                'monitor_key'   => $sample['key'],
                'date_sample'   => $date,
                'status'        => $sample['status'],
                'response_time' => $sample['ping'],
            ]);
            $added++;
        }

        return $added;
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
     * Ordinateurs, matériels réseau et applications des entités données, ou de la session.
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
        if ($entities !== null || (bool) Appliance::canView()) {
            $itemtypes[Appliance::class] = 'appliance';
        }
        $assets = [];
        foreach ($itemtypes as $itemtype => $kind) {
            $table = $itemtype::getTable();
            $where = ['is_deleted' => 0]
                + ($entities === null
                    ? getEntitiesRestrictCriteria($table, '', '', $itemtype === Appliance::class)
                    : [$table . '.entities_id' => $entities === [] ? [-1] : $entities]);
            if ($itemtype !== Appliance::class) {
                $where['is_template'] = 0;
            }
            if ($excluded !== []) {
                $where[] = ['NOT' => ['states_id' => $excluded]];
            }
            $request = ['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => $table, 'WHERE' => $where];
            foreach ($DB->request($request) as $row) {
                $id = (int) $row['id'];
                $assets[$itemtype . ':' . $id] = [
                    'itemtype'    => $itemtype,
                    'kind'        => $kind,
                    'id'          => $id,
                    'name'        => (string) $row['name'],
                    'entities_id' => (int) $row['entities_id'],
                ];
            }
        }

        return $assets;
    }

    /**
     * Classification C/I/D et publication sur Internet des actifs.
     *
     * @param array<string, array<string, mixed>> $assets
     * @return array{critical: array<string, bool>, exposed: array<string, bool>}
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
            $critical[$key] = ZabbixRules::isCritical(
                (string) ($level['confidentiality'] ?? ''),
                (string) ($level['integrity'] ?? ''),
                (string) ($level['availability'] ?? ''),
                isset($exposed[$key])
            );
        }

        return ['critical' => $critical, 'exposed' => $exposed];
    }

    /**
     * Index de rapprochement : noms complets et courts, adresses IP.
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
                $names[ZabbixRules::normalizeHost($name)] ??= $key;
            }
            if ($asset['itemtype'] !== Appliance::class) {
                $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
            }
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
     * Processus métier lisibles : ceux qui dépendent des actifs (onglet « Dépendances » du
     * processus) et ceux choisis pour une sonde ; aucun sans session.
     *
     * @param array<string, array<string, mixed>> $assets
     * @param list<int> $chosen processus choisis pour des sondes
     * @return array{by_asset: array<string, list<int>>, processes: array<int, array{id: int, name: string,
     *     rto_hours: int, criticity: string}>}
     */
    private static function processes(array $assets, array $chosen, bool $session): array
    {
        global $DB;

        $out = ['by_asset' => [], 'processes' => []];
        if (!$session || !BusinessProcess::canView()) {
            return $out;
        }
        $table = BusinessProcess::getTable();
        $remember = static function (array $row) use (&$out): int {
            $id = (int) $row['id'];
            $criticity = (int) ($row['businesscriticities_id'] ?? 0);
            $out['processes'][$id] ??= [
                'id'        => $id,
                'name'      => (string) $row['name'],
                'rto_hours' => (int) $row['rto_hours'],
                'criticity' => $criticity > 0
                    ? (string) Dropdown::getDropdownName('glpi_businesscriticities', $criticity) : '',
            ];

            return $id;
        };
        $byType = [];
        foreach ($assets as $asset) {
            $byType[(string) $asset['itemtype']][] = (int) $asset['id'];
        }
        foreach ($byType as $itemtype => $ids) {
            foreach (
                $DB->request([
                    'SELECT'     => [
                        BusinessProcessItem::getTable() . '.items_id',
                        $table . '.id',
                        $table . '.name',
                        $table . '.rto_hours',
                        $table . '.businesscriticities_id',
                    ],
                    'FROM'       => BusinessProcessItem::getTable(),
                    'INNER JOIN' => [
                        $table => ['ON' => [
                            BusinessProcessItem::getTable() => 'plugin_grcmanager_businessprocesses_id',
                            $table                          => 'id',
                        ]],
                    ],
                    'WHERE'      => [
                        BusinessProcessItem::getTable() . '.itemtype' => $itemtype,
                        BusinessProcessItem::getTable() . '.items_id' => $ids,
                    ] + getEntitiesRestrictCriteria($table, '', '', true),
                ]) as $row
            ) {
                $out['by_asset'][$itemtype . ':' . (int) $row['items_id']][] = $remember($row);
            }
        }
        $chosen = array_values(array_diff(array_unique($chosen), array_keys($out['processes'])));
        if ($chosen !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'rto_hours', 'businesscriticities_id'],
                    'FROM'   => $table,
                    'WHERE'  => ['id' => $chosen] + getEntitiesRestrictCriteria($table, '', '', true),
                ]) as $row
            ) {
                $remember($row);
            }
        }

        return $out;
    }

    /**
     * Calcul complet : sondes visibles et leur analyse, actifs exposés sans sonde. Sans session (KRI,
     * tâche), passer le périmètre.
     *
     * @param list<int>|null $entities
     * @return array{monitors: list<array<string, mixed>>, exposed: list<array<string, mixed>>, readings: bool}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $settings = UptimeKumaConfig::settings();
        $nowDate = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $now = (int) strtotime($nowDate);
        $from = $now - $settings['period_days'] * 86400;
        $session = $entities === null;
        $assets = self::assets($entities);
        $levels = self::criticality($assets);
        $lookup = self::lookup($assets);

        // Sondes lues, rapprochées ; une sonde sans actif n'est visible que dans l'entité des lectures.
        $monitors = [];
        foreach (
            $DB->request([
                'FROM'  => UptimeKumaTables::MONITORS,
                'WHERE' => self::readingsWhere(UptimeKumaTables::MONITORS, $entities),
                'ORDER' => ['name'],
            ]) as $row
        ) {
            $host = Rules::hostOf((string) $row['url'], (string) $row['hostname']);
            $isIp = $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
            $key = ZabbixRules::match(
                '',
                array_values(array_filter([$isIp ? '' : $host, (string) $row['name']])),
                $isIp ? [$host] : [],
                [],
                [],
                $lookup['names'],
                $lookup['ips']
            );
            $entity = (int) $row['entities_id'];
            if (
                $key === null
                && !($session ? Session::haveAccessToEntity($entity) : in_array($entity, $entities, true))
            ) {
                continue;
            }
            $monitors[(string) $row['monitor_key']] = $row + ['asset_key' => $key, 'host' => $host];
        }
        $readings = $monitors !== [];
        // Relevés et liens : entités des sondes visibles (ces tables ne portent pas d'is_recursive).
        $monitorEntities = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['entities_id'],
            $monitors
        )));

        // Liens faits dans GLPI (processus choisi, incident proposé).
        $links = [];
        if ($monitors !== []) {
            foreach (
                $DB->request([
                    'FROM'  => UptimeKumaTables::LINKS,
                    'WHERE' => [
                        'monitor_key' => array_map('strval', array_keys($monitors)),
                        'entities_id' => $monitorEntities,
                    ],
                ]) as $row
            ) {
                $links[(string) $row['monitor_key']] = $row;
            }
        }

        // Relevés de la période (et le précédent, pour l'état au début de la période).
        $samples = [];
        $pings = [];
        if ($monitors !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['monitor_key', 'date_sample', 'status', 'response_time'],
                    'FROM'   => UptimeKumaTables::SAMPLES,
                    'WHERE'  => [
                        'monitor_key' => array_map('strval', array_keys($monitors)),
                        'date_sample' => ['>=', date('Y-m-d H:i:s', $from - Rules::MAX_SAMPLE_GAP)],
                        'entities_id' => $monitorEntities,
                    ],
                ]) as $row
            ) {
                $time = (int) strtotime((string) $row['date_sample']);
                $samples[(string) $row['monitor_key']][] = [$time, (int) $row['status']];
                if ($row['response_time'] !== null && $time >= $from) {
                    $pings[(string) $row['monitor_key']][] = (int) $row['response_time'];
                }
            }
        }

        $matchedAssets = [];
        foreach ($monitors as $monitor) {
            if ($monitor['asset_key'] !== null) {
                $matchedAssets[$monitor['asset_key']] = $assets[$monitor['asset_key']];
            }
        }
        $chosen = [];
        foreach ($links as $link) {
            if ((int) $link['plugin_grcmanager_businessprocesses_id'] > 0) {
                $chosen[] = (int) $link['plugin_grcmanager_businessprocesses_id'];
            }
        }
        $processes = self::processes($matchedAssets, $chosen, $session);

        $entityNames = [];
        $rows = [];
        foreach ($monitors as $monitorKey => $monitor) {
            $analysis = Rules::analyse($samples[$monitorKey] ?? [], $from, $now);
            $assetKey = $monitor['asset_key'];
            $asset = $assetKey !== null ? $assets[$assetKey] : null;
            $link = $links[$monitorKey] ?? [];
            $chosenProcess = (int) ($link['plugin_grcmanager_businessprocesses_id'] ?? 0);
            $processIds = $assetKey !== null ? ($processes['by_asset'][$assetKey] ?? []) : [];
            if ($chosenProcess > 0) {
                $processIds[] = $chosenProcess;
            }
            $linked = [];
            $rtoGap = false;
            foreach (array_unique($processIds) as $processId) {
                if (!isset($processes['processes'][$processId])) {
                    continue;
                }
                $process = $processes['processes'][$processId];
                $exceeded = Rules::exceedsRto($analysis['longest'], $process['rto_hours']);
                $rtoGap = $rtoGap || $exceeded;
                $linked[] = $process + [
                    'url'      => BusinessProcess::getFormURLWithID($process['id']),
                    'exceeded' => $exceeded,
                    'chosen'   => $process['id'] === $chosenProcess,
                ];
            }
            $critical = ($assetKey !== null && $levels['critical'][$assetKey]) || $linked !== [];
            $certDays = $monitor['cert_days'] !== null ? (int) $monitor['cert_days'] : null;
            $certValid = $monitor['cert_valid'] !== null ? (bool) $monitor['cert_valid'] : null;
            $certStatus = Rules::certStatus($certDays, $certValid, $settings['cert_days']);
            $readDate = (string) ($monitor['date_import'] ?? $nowDate);
            $incidentId = (int) ($link['plugin_grcmanager_securityincidents_id'] ?? 0);
            $incidentOutage = isset($link['date_outage'])
                ? (int) strtotime((string) $link['date_outage']) : null;
            $currentOutage = $analysis['down_since'] !== null ? $now - $analysis['down_since'] : 0;
            $long = Rules::isLongOutage(max($analysis['longest'], $currentOutage), $settings['outage_minutes']);
            $entityId = $asset !== null ? (int) $asset['entities_id'] : (int) $monitor['entities_id'];
            $entityNames[$entityId] ??= $session ? (string) Dropdown::getDropdownName('glpi_entities', $entityId) : '';
            $itemtype = $asset !== null ? (string) $asset['itemtype'] : '';
            $rows[] = [
                'id'            => (int) $monitor['id'],
                'key'           => $monitorKey,
                'name'          => (string) $monitor['name'],
                'type'          => (string) $monitor['type'],
                'url'           => (string) $monitor['url'],
                'host'          => $monitor['host'],
                'status'        => Rules::normalizeStatus($monitor['status']),
                'response_time' => $monitor['response_time'] !== null ? (int) $monitor['response_time'] : null,
                'avg_response'  => isset($pings[$monitorKey])
                    ? (int) round(array_sum($pings[$monitorKey]) / count($pings[$monitorKey])) : null,
                'uptime_24h'    => $monitor['uptime_24h'] !== null ? (float) $monitor['uptime_24h'] : null,
                'availability'  => $analysis['availability'],
                'up'            => $analysis['up'],
                'down'          => $analysis['down'],
                'longest'       => $analysis['longest'],
                'down_since'    => $analysis['down_since'],
                'long_outage'   => $long,
                'outage_start'  => $analysis['down_since'] ?? self::lastOutageStart($samples[$monitorKey] ?? [], $from),
                'cert_days'     => $certDays,
                'cert_status'   => $certStatus,
                'cert_expiry'   => $certDays !== null
                    ? date('Y-m-d', (int) strtotime($readDate) + $certDays * 86400) : '',
                'asset_key'     => $assetKey,
                'itemtype'      => $itemtype,
                'asset_id'      => $asset !== null ? (int) $asset['id'] : 0,
                'asset_name'    => $asset !== null ? (string) $asset['name'] : '',
                'asset_url'     => $asset !== null && $session ? $itemtype::getFormURLWithID((int) $asset['id']) : '',
                'asset_type'    => $asset !== null ? $itemtype::getTypeName(1) : '',
                'entity'        => $entityNames[$entityId],
                'entities_id'   => $entityId,
                'critical'      => $critical,
                'exposed'       => $assetKey !== null && isset($levels['exposed'][$assetKey]),
                'processes'     => $linked,
                'process_id'    => $chosenProcess,
                'rto_gap'       => $rtoGap,
                'incident_id'   => $incidentId,
                'incident_outage' => $incidentOutage,
            ];
        }

        // Actifs publiés sur Internet sans aucune sonde.
        $covered = array_fill_keys(array_filter(array_column($rows, 'asset_key')), true);
        $exposed = [];
        foreach (array_keys($levels['exposed']) as $assetKey) {
            if (isset($covered[$assetKey]) || !isset($assets[$assetKey])) {
                continue;
            }
            $asset = $assets[$assetKey];
            $itemtype = (string) $asset['itemtype'];
            $entityId = (int) $asset['entities_id'];
            $entityNames[$entityId] ??= $session ? (string) Dropdown::getDropdownName('glpi_entities', $entityId) : '';
            $exposed[] = [
                'key'    => $assetKey,
                'name'   => (string) $asset['name'] !== ''
                    ? (string) $asset['name'] : sprintf('%s %d', $itemtype::getTypeName(1), (int) $asset['id']),
                'url'    => $session ? $itemtype::getFormURLWithID((int) $asset['id']) : '',
                'type'   => $itemtype::getTypeName(1),
                'entity' => $entityNames[$entityId],
            ];
        }

        return ['monitors' => $rows, 'exposed' => $exposed, 'readings' => $readings];
    }

    /**
     * Début de la dernière panne terminée de la période (premier relevé DOWN d'une série), ou null.
     *
     * @param list<array{0: int, 1: int}> $samples
     */
    private static function lastOutageStart(array $samples, int $from): ?int
    {
        usort($samples, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $start = null;
        $last = null;
        foreach ($samples as [$time, $status]) {
            if ($status === Rules::STATUS_DOWN) {
                $start ??= $time;
            } elseif ($status === Rules::STATUS_UP || $status === Rules::STATUS_MAINTENANCE) {
                if ($start !== null && $time >= $from) {
                    $last = $start;
                }
                $start = null;
            }
        }

        return $last;
    }

    /**
     * @return array{monitors: list<array<string, mixed>>, exposed: list<array<string, mixed>>, readings: bool}
     */
    private static function data(): array
    {
        if (!self::canView()) {
            return ['monitors' => [], 'exposed' => [], 'readings' => false];
        }
        /** @var array{monitors: list<array<string, mixed>>, exposed: list<array<string, mixed>>, readings: bool} $memo */
        $memo = self::$memo ??= self::compute(null);

        return $memo;
    }

    /**
     * Synthèse : disponibilité de l'ensemble, sondes en panne, certificats, actifs exposés sans sonde.
     *
     * @param array{monitors: list<array<string, mixed>>, exposed: list<array<string, mixed>>}|null $data
     * @return array<string, mixed>
     */
    public static function summary(?array $data = null): array
    {
        $data ??= self::data();
        $settings = UptimeKumaConfig::settings();
        $monitors = $data['monitors'];
        $up = array_sum(array_column($monitors, 'up'));
        $down = array_sum(array_column($monitors, 'down'));
        $availabilities = array_values(array_filter(
            array_column($monitors, 'availability'),
            static fn ($value): bool => $value !== null
        ));
        $certs = array_fill_keys(Rules::CERT_STATUSES, 0);
        foreach ($monitors as $monitor) {
            $certs[$monitor['cert_status']]++;
        }
        $count = static fn (callable $filter): int => count(array_filter($monitors, $filter));

        return [
            'monitors'       => count($monitors),
            'availability'   => $up + $down > 0 ? round(100 * $up / ($up + $down), 2) : null,
            'lowest'         => $availabilities === [] ? null : min($availabilities),
            'down_now'       => $count(static fn (array $row): bool => $row['status'] === Rules::STATUS_DOWN),
            'down_critical'  => $count(
                static fn (array $row): bool => $row['status'] === Rules::STATUS_DOWN && (bool) $row['critical']
            ),
            'long_outages'   => $count(static fn (array $row): bool => (bool) $row['long_outage']),
            'rto_gaps'       => $count(static fn (array $row): bool => (bool) $row['rto_gap']),
            'unmatched'      => $count(static fn (array $row): bool => $row['asset_key'] === null),
            'certs'          => $certs,
            'certs_total'    => count($monitors) - $certs[Rules::CERT_NONE],
            'certs_action'   => $certs[Rules::CERT_EXPIRED] + $certs[Rules::CERT_INVALID]
                + $certs[Rules::CERT_EXPIRING],
            'exposed_without' => count($data['exposed']),
            'period_days'    => $settings['period_days'],
            'cert_days'      => $settings['cert_days'],
            'outage_minutes' => $settings['outage_minutes'],
        ];
    }

    // ------------------------------------------------------------------ Indicateurs (KRI).

    /**
     * KRI « disponibilité des services exposés (%) » sur la période, null sans relevé.
     *
     * @param list<int> $perimeter
     */
    public static function exposedAvailability(array $perimeter): ?float
    {
        $value = self::summary(self::compute($perimeter))['availability'];

        return $value === null ? null : (float) $value;
    }

    /**
     * KRI « certificats TLS expirés, non valides ou proches de l'échéance », null sans lecture.
     *
     * @param list<int> $perimeter
     */
    public static function certificatesToRenew(array $perimeter): ?float
    {
        $data = self::compute($perimeter);

        return $data['readings'] ? (float) self::summary($data)['certs_action'] : null;
    }

    /**
     * KRI « actifs publiés sur Internet sans sonde », null sans lecture.
     *
     * @param list<int> $perimeter
     */
    public static function exposedWithoutMonitor(array $perimeter): ?float
    {
        $data = self::compute($perimeter);

        return $data['readings'] ? (float) self::summary($data)['exposed_without'] : null;
    }

    // ------------------------------------------------------------------ Actions sur clic.

    /**
     * Sonde visible par la personne (ligne de la page), ou null.
     *
     * @return array<string, mixed>|null
     */
    private static function visibleMonitor(int $id): ?array
    {
        foreach (self::data()['monitors'] as $monitor) {
            if ($monitor['id'] === $id) {
                return $monitor;
            }
        }

        return null;
    }

    /**
     * Incident déjà proposé pour cette panne : même début, ou début inconnu.
     *
     * @param array<string, mixed> $monitor
     */
    private static function hasIncidentForOutage(array $monitor): bool
    {
        return $monitor['incident_id'] > 0
            && ($monitor['outage_start'] === null || $monitor['incident_outage'] === null
                || $monitor['incident_outage'] >= $monitor['outage_start']);
    }

    /**
     * Texte de l'incident proposé (texte brut).
     *
     * @param array<string, mixed> $monitor
     */
    private static function outageText(array $monitor): string
    {
        $lines = [
            sprintf(__('Outage reported by Uptime Kuma: %s', 'grcmanager'), $monitor['name']),
        ];
        if ($monitor['url'] !== '' || $monitor['host'] !== '') {
            $lines[] = sprintf(
                __('Monitored address: %s', 'grcmanager'),
                $monitor['url'] !== '' ? $monitor['url'] : $monitor['host']
            );
        }
        if ($monitor['outage_start'] !== null) {
            $lines[] = sprintf(
                __('Outage started on: %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s', (int) $monitor['outage_start']))
            );
        }
        $lines[] = sprintf(
            __('Longest outage over the period: %s', 'grcmanager'),
            self::durationLabel(max((int) $monitor['longest'], 0))
        );
        foreach ($monitor['processes'] as $process) {
            $lines[] = sprintf(__('Business process concerned: %s', 'grcmanager'), $process['name']);
        }

        return implode("\n", $lines);
    }

    /**
     * Crée, sur clic, un incident de sécurité à partir d'une panne longue (une seule fois par
     * panne), rattaché à l'actif. Renvoie l'identifiant de l'incident, 0 sinon.
     */
    public static function proposeIncident(int $monitorId): int
    {
        if (!self::canView() || !PluginGrcmanagerSecurityIncident::canCreate()) {
            return 0;
        }
        $monitor = self::visibleMonitor($monitorId);
        if ($monitor === null || !$monitor['long_outage'] || self::hasIncidentForOutage($monitor)) {
            return 0;
        }
        $entity = Session::haveAccessToEntity((int) $monitor['entities_id'])
            ? (int) $monitor['entities_id'] : (int) Session::getActiveEntity();
        $start = $monitor['outage_start'] !== null ? date('Y-m-d H:i:s', (int) $monitor['outage_start']) : null;
        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name'        => mb_substr(sprintf('Uptime Kuma: %s', $monitor['name']), 0, 250),
            'content'     => self::outageText($monitor),
            'date'        => $start ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            'severity'    => Rules::incidentSeverity((bool) $monitor['rto_gap'], (bool) $monitor['critical']),
            'category'    => 'availability',
            'source'      => 'monitoring',
            'external_id' => mb_substr(
                'uptimekuma:' . $monitor['key'] . ':' . (string) $monitor['outage_start'],
                0,
                255
            ),
            'entities_id' => $entity,
        ]);
        if ($incidentId <= 0) {
            return 0;
        }
        if ($monitor['asset_key'] !== null) {
            (new PluginGrcmanagerSecurityIncident_Item())->add([
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'itemtype' => $monitor['itemtype'],
                'items_id' => $monitor['asset_id'],
            ]);
        }
        self::saveLink($monitor['key'], [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'date_outage' => $start,
        ]);
        AuditLog::record('create', self::class, 0, $incidentId, 'success', ['monitor' => $monitor['key']]);
        self::reset();

        return $incidentId;
    }

    /**
     * Rattache une sonde (ligne visible) à un processus métier lisible, ou l'en détache (0).
     */
    public static function linkProcess(int $monitorId, int $processId): bool
    {
        if (!self::canManage()) {
            return false;
        }
        $monitor = self::visibleMonitor($monitorId);
        if ($monitor === null) {
            return false;
        }
        if ($processId > 0) {
            $process = new BusinessProcess();
            if (!$process->getFromDB($processId) || !$process->canViewItem()) {
                return false;
            }
        }
        $ok = self::saveLink($monitor['key'], ['plugin_grcmanager_businessprocesses_id' => max(0, $processId)]);
        self::reset();

        return $ok;
    }

    /**
     * Enregistre un lien d'une sonde de l'entité des lectures (ligne créée au besoin).
     *
     * @param array<string, mixed> $fields
     */
    private static function saveLink(string $monitorKey, array $fields): bool
    {
        global $DB;

        $entity = UptimeKumaConfig::entity();
        $where = ['entities_id' => $entity, 'monitor_key' => $monitorKey];
        if (countElementsInTable(UptimeKumaTables::LINKS, $where) > 0) {
            return (bool) $DB->update(UptimeKumaTables::LINKS, $fields, $where);
        }

        return (bool) $DB->insert(UptimeKumaTables::LINKS, $where + $fields);
    }

    /**
     * Efface les lectures (sondes et relevés) de l'entité des lectures ; les processus choisis et
     * les incidents créés restent.
     */
    public static function clear(): bool
    {
        global $DB;

        if (!self::canManage() || !Session::haveAccessToEntity(UptimeKumaConfig::entity())) {
            return false;
        }
        foreach ([UptimeKumaTables::MONITORS, UptimeKumaTables::SAMPLES] as $table) {
            $DB->delete($table, ['entities_id' => UptimeKumaConfig::entity()]);
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
            ? ['description' => __(
                'Uptime Kuma: reads monitors, availability and TLS certificates (read only)',
                'grcmanager'
            )]
            : [];
    }

    public static function cronUptimekuma(CronTask $task): int
    {
        if (!UptimeKumaConfig::isEnabled() || !UptimeKumaConfig::isConfigured()) {
            return 0;
        }
        $result = self::read(null, true);
        if ($result['error'] !== '') {
            // Code d'erreur seulement : jamais la clé d'API.
            $task->log('Uptime Kuma: ' . $result['error']);

            return 0;
        }
        $task->addVolume($result['monitors']);

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

    /** Durée lisible (« 2 heures 5 minutes »). */
    private static function durationLabel(int $seconds): string
    {
        return (string) Html::timestampToString(max(0, $seconds), false);
    }

    /**
     * Libellés d'affichage d'une sonde.
     *
     * @param array<string, mixed> $monitor
     * @return array<string, mixed>
     */
    private static function decorate(array $monitor): array
    {
        $durations = BusinessProcess::getDurationOptions();
        $monitor['longest_label'] = $monitor['longest'] > 0 ? self::durationLabel((int) $monitor['longest']) : '';
        $monitor['down_since_label'] = $monitor['down_since'] !== null
            ? (string) Html::convDateTime(date('Y-m-d H:i:s', (int) $monitor['down_since'])) : '';
        $monitor['cert_expiry_label'] = $monitor['cert_expiry'] !== ''
            ? (string) Html::convDate($monitor['cert_expiry']) : '';
        foreach ($monitor['processes'] as &$process) {
            $process['rto'] = $durations[$process['rto_hours']] ?? '';
        }
        unset($process);
        $monitor['incident_url'] = $monitor['incident_id'] > 0
            ? PluginGrcmanagerSecurityIncident::getFormURLWithID($monitor['incident_id']) : '';
        // Annonce aux utilisateurs : onglet « Communication de crise » de l'incident (#616).
        $monitor['crisis_url'] = $monitor['incident_id'] > 0 && CrisisCommunication::canView()
            ? $monitor['incident_url'] . '&forcetab=' . rawurlencode(CrisisCommunication::class . '$1') : '';
        $monitor['can_propose'] = $monitor['long_outage'] && !self::hasIncidentForOutage($monitor);

        return $monitor;
    }

    /**
     * Processus métier lisibles, pour le rattachement d'une sonde.
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
                'SELECT' => ['id', 'name'],
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
        $monitors = $data['monitors'];
        // En panne d'abord, puis critiques, écarts au RTO, disponibilité la plus basse, nom.
        $rank = static fn (array $row): array => [
            $row['status'] !== Rules::STATUS_DOWN, !$row['critical'], !$row['rto_gap'],
            $row['availability'] ?? 101.0, mb_strtolower((string) $row['name']),
        ];
        usort($monitors, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));
        $certRank = array_flip(Rules::CERT_STATUSES);
        $certs = array_values(array_filter(
            $monitors,
            static fn (array $row): bool => $row['cert_status'] !== Rules::CERT_NONE
        ));
        usort(
            $certs,
            static fn (array $a, array $b): int => [$certRank[$a['cert_status']], $a['cert_days'] ?? PHP_INT_MAX]
                <=> [$certRank[$b['cert_status']], $b['cert_days'] ?? PHP_INT_MAX]
        );
        $lastRead = UptimeKumaConfig::lastRead();

        return [
            'summary'         => $summary,
            'statuses'        => self::statusInfos(),
            'cert_infos'      => self::certInfos(),
            'monitors'        => array_map(self::decorate(...), array_slice($monitors, 0, self::MAX_ROWS)),
            'total_monitors'  => count($monitors),
            'certificates'    => array_map(self::decorate(...), array_slice($certs, 0, self::MAX_ROWS)),
            'exposed'         => array_slice($data['exposed'], 0, self::MAX_ROWS),
            'total_exposed'   => count($data['exposed']),
            'process_choices' => self::processChoices(),
            'readings'        => $data['readings'],
            'settings'        => UptimeKumaConfig::settings(),
            // La clé d'API n'est jamais renvoyée, seulement sa présence.
            'connection'      => [
                'url'           => UptimeKumaConfig::rawUrl(),
                'has_key'       => UptimeKumaConfig::hasApiKey(),
                'pages'         => implode(', ', UptimeKumaConfig::statusPages()),
                'configured'    => UptimeKumaConfig::isConfigured(),
                'entity'        => UptimeKumaConfig::entity(),
                'enabled'       => UptimeKumaConfig::isEnabled(),
                'allow_private' => \GlpiPlugin\Grcmanager\Compat\Http::allowsPrivateNetworks(),
            ],
            'last_read'       => $lastRead + [
                'date_label'  => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
                'error_label' => $lastRead['error'] !== ''
                    ? self::errorMessage($lastRead['error'], $lastRead['page']) : '',
            ],
            'can_manage'      => self::canManage(),
            'can_incident'    => (bool) PluginGrcmanagerSecurityIncident::canCreate(),
            'action'          => self::getSearchURL(),
            // Issue #1042 : la connexion se règle dans l'onglet Connecteurs de la configuration.
            'connector_url'   => \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl(
                \GlpiPlugin\Grcmanager\Config\Block\UptimeKumaConnectorBlock::ANCHOR
            ),
            'processes_url'   => BusinessProcess::getSearchURL(),
            'flows_url'       => NetworkFlow::getSearchURL(),
            'kri_url'         => RiskIndicator::getSearchURL(),
            'audit_url'       => ComplianceAudit::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/uptime_kuma.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Vue sécurité 360° et preuves.

    /**
     * Sondes rapprochées d'un actif, pour la vue sécurité 360° (#837).
     *
     * @return list<array<string, mixed>>
     */
    public static function monitorsOf(CommonDBTM $item): array
    {
        $key = $item::class . ':' . (int) $item->getID();

        return array_values(array_filter(
            self::data()['monitors'],
            static fn (array $row): bool => $row['asset_key'] === $key
        ));
    }

    /**
     * Preuve des mesures A.8.16 (services surveillés, actifs exposés sans sonde), A.5.30
     * (disponibilité, pannes au-delà du RTO) et A.8.24 (certificats). Null si la mesure n'est pas
     * concernée, sans les droits ou sans lecture.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.16', 'A.5.30', 'A.8.24'], true) || !self::canView()) {
            return null;
        }
        $data = self::data();
        if (!$data['readings']) {
            return null;
        }
        $summary = self::summary($data);
        $lastRead = UptimeKumaConfig::lastRead();

        return [
            'code'            => $code,
            'monitors'        => $summary['monitors'],
            'down_now'        => $summary['down_now'],
            'exposed_without' => $summary['exposed_without'],
            'availability'    => $summary['availability'],
            'rto_gaps'        => $summary['rto_gaps'],
            'long_outages'    => $summary['long_outages'],
            'certs_total'     => $summary['certs_total'],
            'certs'           => $summary['certs'],
            'cert_days'       => $summary['cert_days'],
            'period_days'     => $summary['period_days'],
            'date'            => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
            'url'             => self::getSearchURL(),
        ];
    }
}

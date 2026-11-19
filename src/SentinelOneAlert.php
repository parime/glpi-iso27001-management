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

namespace GlpiPlugin\Grcmanager;

use Computer;
use CronTask;
use GlpiPlugin\Grcmanager\Compat\Base\SentinelOneAlertBase;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneClient;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneParser;
use PluginGrcmanagerSecurityIncident_Item;
use Session;

/**
 * Issue #707 : connecteur SentinelOne en lecture seule.
 *
 * La tâche automatique (désactivée par défaut) lit les agents, les nouvelles menaces et alertes et
 * les vulnérabilités applicatives. Les menaces et alertes ne deviennent JAMAIS des incidents
 * d'elles-mêmes : elles sont gardées ici comme propositions (statut « pending »), revues dans
 * l'écran de l'import EDR, puis créées par SecurityIncidentEdrImport::import() comme une ligne de
 * fichier CSV (même anti-doublon par identifiant d'alerte), ou écartées. Rien n'est jamais écrit
 * vers SentinelOne.
 *
 * Cloisonnement par entité : une proposition prend l'entité de l'ordinateur GLPI reconnu, sinon
 * l'entité choisie dans la configuration ; on ne voit et ne traite que celles de ses entités.
 */
class SentinelOneAlert extends SentinelOneAlertBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_IGNORED = 'ignored';

    public const AGENTS_TABLE = 'glpi_plugin_grcmanager_sentineloneagents';
    public const VULNERABILITIES_TABLE = 'glpi_plugin_grcmanager_sentinelonevulnerabilities';

    /** Codes d'erreur affichables (bilan du dernier passage). */
    public const ERROR_CODES = [
        SentinelOneClient::ERROR_UNAUTHORIZED, SentinelOneClient::ERROR_FORBIDDEN, SentinelOneClient::ERROR_NOT_FOUND,
        SentinelOneClient::ERROR_RATE_LIMITED, SentinelOneClient::ERROR_UNREACHABLE, SentinelOneClient::ERROR_INTERNAL,
        SentinelOneClient::ERROR_INVALID, SentinelOneClient::ERROR_STATUS, self::SKIPPED,
    ];

    /** Liste non lue pendant ce passage (une liste précédente l'a arrêté). */
    public const SKIPPED = 'skipped';

    /** Propositions affichées au plus dans l'écran de vérification. */
    public const MAX_SHOWN = 200;

    /** Erreurs qui arrêtent le passage (les autres listes ne répondraient pas mieux). */
    private const BLOCKING_ERRORS = [
        SentinelOneClient::ERROR_UNAUTHORIZED,
        SentinelOneClient::ERROR_RATE_LIMITED,
        SentinelOneClient::ERROR_UNREACHABLE,
        SentinelOneClient::ERROR_INTERNAL,
        SentinelOneClient::ERROR_INVALID,
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_sentinelonealerts';
    }

    public static function getTypeName($nb = 0)
    {
        return __('SentinelOne alerts', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-search';
    }

    /** Les propositions ne se modifient pas à la main. */
    public function canUpdateItem(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'sentinelone'
            ? ['description' => __(
                'SentinelOne: reads new threats, alerts and application vulnerabilities (read only)',
                'grcmanager'
            )]
            : [];
    }

    public static function cronSentinelone(CronTask $task): int
    {
        if (!SentinelOneConfig::isEnabled() || !SentinelOneConfig::isConfigured()) {
            return 0;
        }
        $steps = self::synchronize(SentinelOneConfig::client());
        $volume = 0;
        foreach ($steps as $name => $step) {
            $volume += $step['items'];
            if ($step['error'] !== '') {
                // Code d'erreur et libellé seulement : ni jeton, ni adresse complète.
                $task->log(sprintf('SentinelOne %s: %s', $name, self::errorLabel($step['error'])));
            }
        }
        $task->addVolume($volume);

        return $volume > 0 ? 1 : 0;
    }

    /**
     * Un passage complet : agents, menaces, alertes, vulnérabilités. Bilan par liste, aussi gardé
     * dans la configuration (affiché sur la page de configuration).
     *
     * @return array<string, array{items: int, new: int, complete: bool, error: string}>
     */
    public static function synchronize(SentinelOneClient $client): array
    {
        $steps = [];
        $stop = false;
        foreach (['agents', 'threats', 'alerts', 'vulnerabilities'] as $list) {
            if ($stop) {
                $steps[$list] = ['items' => 0, 'new' => 0, 'complete' => false, 'error' => self::SKIPPED];
                continue;
            }
            $steps[$list] = match ($list) {
                'agents'          => self::syncAgents($client),
                'vulnerabilities' => self::syncVulnerabilities($client),
                default           => self::syncProposals($client, $list),
            };
            $stop = in_array($steps[$list]['error'], self::BLOCKING_ERRORS, true);
        }
        SentinelOneConfig::setLastRun($steps);

        return $steps;
    }

    /**
     * @return array{items: int, new: int, complete: bool, error: string}
     */
    public static function syncAgents(SentinelOneClient $client): array
    {
        global $DB;

        $seen = [];
        $new = 0;
        $fetch = $client->fetchAll('agents', ['limit' => '1000'], static function (array $items) use (&$seen, &$new, $DB): void {
            foreach ($items as $item) {
                $agent = SentinelOneParser::agent($item);
                if ($agent === null) {
                    continue;
                }
                $computerId = self::matchComputer($agent['computer_name'], $agent['serial']);
                $row = [
                    'agent_id'      => $agent['agent_id'],
                    'uuid'          => $agent['uuid'],
                    'computer_name' => $agent['computer_name'],
                    'serial'        => $agent['serial'],
                    'os'            => $agent['os'],
                    'is_active'     => $agent['is_active'] ? 1 : 0,
                    'last_active'   => EdrCsvImport::parseDate($agent['last_active']),
                    'computers_id'  => $computerId,
                    'entities_id'   => $computerId > 0 ? self::entityOfComputer($computerId) : SentinelOneConfig::entityId(),
                    'date_mod'      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ];
                $existing = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => self::AGENTS_TABLE,
                    'WHERE'  => ['agent_id' => $agent['agent_id']],
                ])->current();
                if ($existing !== null) {
                    $DB->update(self::AGENTS_TABLE, $row, ['id' => (int) $existing['id']]);
                    $seen[] = (int) $existing['id'];
                } else {
                    $DB->insert(self::AGENTS_TABLE, $row);
                    $seen[] = (int) $DB->insertId();
                    $new++;
                }
            }
        });
        // Liste complète : les agents disparus de la console sont oubliés.
        if ($fetch['complete']) {
            $DB->delete(self::AGENTS_TABLE, $seen === [] ? ['id' => ['>', 0]] : ['NOT' => ['id' => $seen]]);
        }

        return ['items' => $fetch['items'], 'new' => $new, 'complete' => $fetch['complete'], 'error' => $fetch['error']];
    }

    /**
     * Nouvelles menaces ou alertes depuis la dernière lecture, gardées comme propositions. Une
     * alerte déjà connue (proposée, importée par l'API ou par un fichier CSV) n'est pas reprise.
     *
     * @param 'threats'|'alerts' $list
     * @return array{items: int, new: int, complete: bool, error: string}
     */
    public static function syncProposals(SentinelOneClient $client, string $list): array
    {
        $since = SentinelOneConfig::since($list);
        $latest = $since;
        $ordered = true;
        $new = 0;
        $console = SentinelOneConfig::url();
        $fetch = $client->fetchAll(
            $list,
            ['createdAt__gt' => $since, 'sortBy' => 'createdAt', 'sortOrder' => 'asc'],
            static function (array $items) use ($list, $console, &$latest, &$ordered, &$new): void {
                foreach ($items as $item) {
                    $proposal = $list === 'alerts'
                        ? SentinelOneParser::alert($item, $console)
                        : SentinelOneParser::threat($item, $console);
                    if ($proposal === null) {
                        continue;
                    }
                    $created = $proposal['detected_at'] !== '' ? SentinelOneParser::apiDate($proposal['detected_at']) : '';
                    if ($created !== '') {
                        $ordered = $ordered && strcmp($created, $latest) >= 0;
                        $latest = strcmp($created, $latest) > 0 ? $created : $latest;
                    }
                    $new += self::propose($proposal) ? 1 : 0;
                }
            }
        );
        // Reprise : la date avance si la liste est complète, ou si elle arrive triée (une lecture
        // interrompue reprend alors après la dernière alerte lue, sans en sauter).
        if ($latest !== $since && ($fetch['complete'] || $ordered)) {
            SentinelOneConfig::setSince($list, $latest);
        }

        return ['items' => $fetch['items'], 'new' => $new, 'complete' => $fetch['complete'], 'error' => $fetch['error']];
    }

    /**
     * Enregistre une proposition si son identifiant est inconnu. Renvoie true si elle est nouvelle.
     *
     * @param array<string, string> $proposal
     */
    public static function propose(array $proposal): bool
    {
        global $DB;

        $alertId = mb_substr($proposal['alert_id'], 0, 64);
        if (
            countElementsInTable(self::getTable(), ['external_id' => $alertId]) > 0
            || countElementsInTable(SecurityIncidentEdrImport::getAlertsTable(), ['alert_id' => $alertId]) > 0
        ) {
            return false;
        }
        $computerId = self::computerOfAgent($proposal['agent_id']);
        if ($computerId === 0) {
            $computerId = self::matchComputer($proposal['hostname'], '');
        }

        return $DB->insert(self::getTable(), [
            'external_id'   => $alertId,
            'kind'          => $proposal['kind'] === 'alert' ? 'alert' : 'threat',
            'name'          => mb_substr($proposal['title'] !== '' ? $proposal['title'] : 'SentinelOne: ' . $proposal['hostname'], 0, 255),
            'detected_at'   => EdrCsvImport::parseDate($proposal['detected_at']),
            'severity'      => EdrCsvImport::severity($proposal['severity']),
            'hostname'      => mb_substr($proposal['hostname'], 0, 255),
            'username'      => mb_substr($proposal['username'], 0, 255),
            'agent_id'      => mb_substr($proposal['agent_id'], 0, 64),
            'computers_id'  => $computerId,
            'entities_id'   => $computerId > 0 ? self::entityOfComputer($computerId) : SentinelOneConfig::entityId(),
            'data'          => (string) json_encode($proposal, JSON_UNESCAPED_UNICODE),
            'status'        => self::STATUS_PENDING,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'date_mod'      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]) !== false;
    }

    /**
     * Vulnérabilités applicatives (si la licence les expose). Liste complète : elle remplace la
     * précédente ; interrompue : les lignes lues sont ajoutées ou mises à jour, rien n'est retiré.
     *
     * @return array{items: int, new: int, complete: bool, error: string}
     */
    public static function syncVulnerabilities(SentinelOneClient $client): array
    {
        global $DB;

        $seen = [];
        $new = 0;
        $fetch = $client->fetchAll('vulnerabilities', ['limit' => '1000'], static function (array $items) use (&$seen, &$new, $DB): void {
            foreach ($items as $item) {
                $finding = SentinelOneParser::vulnerability($item);
                if ($finding === null) {
                    continue;
                }
                $key = sha1(implode('|', [$finding['cve'], $finding['endpoint_id'], $finding['endpoint_name'], $finding['application'], $finding['version']]));
                $computerId = self::computerOfAgent($finding['endpoint_id']);
                if ($computerId === 0) {
                    $computerId = self::matchComputer($finding['endpoint_name'], '');
                }
                $row = [
                    'unicity'       => $key,
                    'cve_id'        => $finding['cve'],
                    'endpoint_id'   => $finding['endpoint_id'],
                    'endpoint_name' => $finding['endpoint_name'],
                    'application'   => $finding['application'],
                    'version'       => $finding['version'],
                    'score'         => $finding['score'],
                    'severity'      => $finding['severity'],
                    'computers_id'  => $computerId,
                    'date_mod'      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ];
                $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::VULNERABILITIES_TABLE, 'WHERE' => ['unicity' => $key]])->current();
                if ($existing !== null) {
                    $DB->update(self::VULNERABILITIES_TABLE, $row, ['id' => (int) $existing['id']]);
                    $seen[] = (int) $existing['id'];
                } else {
                    $DB->insert(self::VULNERABILITIES_TABLE, $row);
                    $seen[] = (int) $DB->insertId();
                    $new++;
                }
            }
        });
        if ($fetch['complete']) {
            $DB->delete(self::VULNERABILITIES_TABLE, $seen === [] ? ['id' => ['>', 0]] : ['NOT' => ['id' => $seen]]);
        }

        return ['items' => $fetch['items'], 'new' => $new, 'complete' => $fetch['complete'], 'error' => $fetch['error']];
    }

    /**
     * Ordinateur GLPI d'un agent : numéro de série d'abord, puis nom exact (ou nom court d'un nom
     * complet). Un nom ou un numéro partagé par plusieurs ordinateurs ne rapproche rien : jamais
     * de rapprochement par ressemblance.
     */
    public static function matchComputer(string $name, string $serial): int
    {
        $base = ['is_deleted' => 0, 'is_template' => 0];
        $serial = trim($serial);
        if ($serial !== '') {
            $ids = self::computerIds($base + ['serial' => $serial]);
            if (count($ids) === 1) {
                return $ids[0];
            }
        }
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $short = strtok($name, '.') ?: $name;
        foreach (array_unique([$name, $short]) as $candidate) {
            $ids = self::computerIds($base + ['name' => $candidate]);
            if (count($ids) === 1) {
                return $ids[0];
            }
            if (count($ids) > 1) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return list<int>
     */
    private static function computerIds(array $criteria): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Computer::getTable(), 'WHERE' => $criteria, 'LIMIT' => 2]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private static function computerOfAgent(string $agentId): int
    {
        global $DB;

        if ($agentId === '') {
            return 0;
        }
        $row = $DB->request([
            'SELECT' => ['computers_id'],
            'FROM'   => self::AGENTS_TABLE,
            'WHERE'  => ['agent_id' => $agentId],
        ])->current();

        return (int) ($row['computers_id'] ?? 0);
    }

    private static function entityOfComputer(int $computerId): int
    {
        $computer = new Computer();

        return $computer->getFromDB($computerId) ? (int) $computer->fields['entities_id'] : SentinelOneConfig::entityId();
    }

    /**
     * Propositions en attente des entités actives de l'utilisateur, les plus récentes d'abord.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function pendingForCurrentUser(): array
    {
        global $DB;

        $where = ['status' => self::STATUS_PENDING] + getEntitiesRestrictCriteria(self::getTable(), '', '', false);
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['detected_at DESC', 'id DESC'],
                'LIMIT' => self::MAX_SHOWN,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => countElementsInTable(self::getTable(), $where)];
    }

    /**
     * Données de l'écran de vérification (import EDR) : null si le connecteur n'est pas configuré
     * et que rien n'attend.
     *
     * @return array<string, mixed>|null
     */
    public static function reviewData(): ?array
    {
        global $DB, $CFG_GLPI;

        if (!$DB->tableExists(self::getTable())) {
            return null;
        }
        $pending = self::pendingForCurrentUser();
        if (!SentinelOneConfig::isConfigured() && $pending['total'] === 0) {
            return null;
        }
        $rows = [];
        foreach ($pending['rows'] as $row) {
            $computerId = (int) $row['computers_id'];
            $data = json_decode((string) $row['data'], true);
            $consoleUrl = is_array($data) ? (string) ($data['console_url'] ?? '') : '';
            $rows[] = [
                'id'           => (int) $row['id'],
                'kind'         => (string) $row['kind'],
                'name'         => (string) $row['name'],
                'detected_at'  => (string) $row['detected_at'],
                'severity'     => (string) $row['severity'],
                'hostname'     => (string) $row['hostname'],
                'username'     => (string) $row['username'],
                'computer'     => $computerId > 0 ? \Dropdown::getDropdownName(Computer::getTable(), $computerId) : '',
                'computer_url' => $computerId > 0 ? Computer::getFormURLWithID($computerId) : '',
                'entity'       => \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                // Lien vers la console seulement s'il est bien en HTTP(S).
                'console_url'  => preg_match('#^https?://#i', $consoleUrl) === 1 ? $consoleUrl : '',
            ];
        }
        $run = SentinelOneConfig::lastRun();

        return [
            'rows'       => $rows,
            'total'      => $pending['total'],
            'enabled'    => SentinelOneConfig::isEnabled(),
            'last_run'   => $run['date'] ?? '',
            'kinds'      => self::getKindLabels(),
            'severities' => \PluginGrcmanagerSecurityIncident::getSeverities(),
            'can_import' => SecurityIncidentEdrImport::canImport(),
            'config_url' => Session::haveRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE)
                ? $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-sentinelone' : '',
        ];
    }

    /**
     * Crée un incident par proposition choisie (une alerte = une ligne de l'import EDR), dans
     * l'entité de la proposition, et y rattache l'ordinateur reconnu. Une proposition hors des
     * entités de l'utilisateur, déjà traitée ou déjà importée est ignorée.
     *
     * @param list<int> $ids
     * @return array{created: list<int>, updated: list<int>, skipped: int}
     */
    public static function importProposals(array $ids): array
    {
        $result = ['created' => [], 'updated' => [], 'skipped' => 0];
        if (!SecurityIncidentEdrImport::canImport()) {
            return $result;
        }
        foreach (array_unique($ids) as $id) {
            $proposal = new self();
            if (!self::canDecide($proposal, $id)) {
                $result['skipped']++;
                continue;
            }
            $data = json_decode((string) $proposal->fields['data'], true);
            $data = is_array($data) ? array_map('strval', array_filter($data, 'is_scalar')) : [];
            $import = SentinelOneParser::toImportRow($data);
            $outcome = SecurityIncidentEdrImport::import(
                ['headers' => $import['headers'], 'rows' => [$import['row']]],
                $import['mapping'],
                'detection',
                'UTC',
                0,
                'SentinelOne API',
                hash('sha256', (string) $proposal->fields['data']),
                (int) $proposal->fields['entities_id']
            );
            $incidentId = (int) ($outcome['created'][0] ?? 0);
            if ($incidentId > 0) {
                $result['created'][] = $incidentId;
                $computerId = (int) $proposal->fields['computers_id'];
                if ($computerId > 0) {
                    self::linkComputer($incidentId, $computerId);
                }
            } else {
                $result['skipped']++;
            }
            self::decide((int) $id, self::STATUS_IMPORTED, $incidentId);
        }

        return $result;
    }

    /**
     * Écarte les propositions choisies (fausse alerte, déjà traitée ailleurs).
     *
     * @param list<int> $ids
     */
    public static function ignoreProposals(array $ids): int
    {
        $count = 0;
        if (!SecurityIncidentEdrImport::canImport()) {
            return 0;
        }
        foreach (array_unique($ids) as $id) {
            $proposal = new self();
            if (self::canDecide($proposal, $id)) {
                self::decide((int) $id, self::STATUS_IGNORED, 0);
                $count++;
            }
        }

        return $count;
    }

    private static function canDecide(self $proposal, int $id): bool
    {
        return $proposal->getFromDB($id)
            && $proposal->fields['status'] === self::STATUS_PENDING
            && Session::haveAccessToEntity((int) $proposal->fields['entities_id']);
    }

    private static function decide(int $id, string $status, int $incidentId): void
    {
        global $DB;

        $DB->update(self::getTable(), [
            'status'                                 => $status,
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'users_id'                               => (int) Session::getLoginUserID(),
            'date_mod'                               => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $id, 'status' => self::STATUS_PENDING]);
    }

    private static function linkComputer(int $incidentId, int $computerId): void
    {
        $criteria = [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ];
        if (countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), $criteria) === 0) {
            (new PluginGrcmanagerSecurityIncident_Item())->add($criteria);
        }
    }

    /**
     * Agents de la console sans ordinateur GLPI reconnu (nom et numéro de série), pour les rapprocher
     * à la main dans l'inventaire.
     *
     * @return array{rows: list<array{computer_name: string, serial: string, os: string}>, total: int, agents: int}
     */
    public static function unmatchedAgents(int $limit = 20): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['computer_name', 'serial', 'os'],
                'FROM'   => self::AGENTS_TABLE,
                'WHERE'  => ['computers_id' => 0],
                'ORDER'  => ['computer_name'],
                'LIMIT'  => $limit,
            ]) as $row
        ) {
            $rows[] = [
                'computer_name' => (string) $row['computer_name'],
                'serial'        => (string) $row['serial'],
                'os'            => (string) $row['os'],
            ];
        }

        return [
            'rows'   => $rows,
            'total'  => countElementsInTable(self::AGENTS_TABLE, ['computers_id' => 0]),
            'agents' => countElementsInTable(self::AGENTS_TABLE),
        ];
    }

    /**
     * Message lisible d'un code d'erreur (jamais le jeton ni l'adresse complète).
     */
    public static function errorLabel(string $error): string
    {
        return match ($error) {
            SentinelOneClient::ERROR_UNAUTHORIZED => __('SentinelOne refused the API token (401): check that it is valid and has not expired.', 'grcmanager'),
            SentinelOneClient::ERROR_FORBIDDEN    => __('The API token is not allowed to read this information (403): give it the Viewer role on the accounts or sites to read, or check the licence.', 'grcmanager'),
            SentinelOneClient::ERROR_NOT_FOUND    => __('This information is not available on this console (404): it may not be included in the licence.', 'grcmanager'),
            SentinelOneClient::ERROR_RATE_LIMITED => __('SentinelOne limits the number of requests (429): the next run resumes where this one stopped.', 'grcmanager'),
            SentinelOneClient::ERROR_UNREACHABLE  => __('The SentinelOne console could not be reached: check its address, the GLPI proxy and, for an internal address, the setting that allows them.', 'grcmanager'),
            SentinelOneClient::ERROR_INTERNAL     => __('The console address is an internal address, refused by default: an administrator can allow internal addresses in this configuration page.', 'grcmanager'),
            SentinelOneClient::ERROR_INVALID      => __('The SentinelOne console sent an unexpected answer: check the console address.', 'grcmanager'),
            self::SKIPPED            => __('Not read during this run: an earlier step stopped it.', 'grcmanager'),
            ''                       => '',
            default                  => __('The SentinelOne console returned an error.', 'grcmanager'),
        };
    }

    /**
     * La tâche automatique suit l'interrupteur du connecteur (désactivée par défaut).
     */
    public static function syncCronState(bool $enabled): void
    {
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'sentinelone')) {
            $state = $enabled ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
            if ((int) $task->fields['state'] !== $state && !($enabled && (int) $task->fields['state'] === CronTask::STATE_RUNNING)) {
                $task->update(['id' => $task->getID(), 'state' => $state]);
            }
        }
    }

    /**
     * Données de la carte de configuration. Le jeton n'y figure jamais : seulement sa présence.
     *
     * @return array<string, mixed>
     */
    public static function configData(): array
    {
        global $DB;

        $task = new CronTask();
        $hasTask = $task->getFromDBbyName(self::class, 'sentinelone');
        $tablesReady = $DB->tableExists(self::AGENTS_TABLE);
        $pending = $tablesReady ? countElementsInTable(self::getTable(), ['status' => self::STATUS_PENDING]) : 0;

        return [
            'enabled'      => SentinelOneConfig::isEnabled(),
            'configured'   => SentinelOneConfig::isConfigured(),
            'url'          => SentinelOneConfig::rawUrl(),
            'has_token'    => SentinelOneConfig::hasToken(),
            'entities_id'  => SentinelOneConfig::entityId(),
            'last_run'     => SentinelOneConfig::lastRun(),
            'cron_url'     => $hasTask ? CronTask::getFormURLWithID($task->getID()) : '',
            'cron_active'  => $hasTask && (int) $task->fields['state'] !== CronTask::STATE_DISABLE,
            'review_url'   => $pending > 0 ? SecurityIncidentEdrImport::getImportURL() . '#grc-sentinelone-review' : '',
            'agents'       => $tablesReady ? self::unmatchedAgents() : ['rows' => [], 'total' => 0, 'agents' => 0],
            'step_labels'  => self::getStepLabels(),
            'error_labels' => array_combine(self::ERROR_CODES, array_map([self::class, 'errorLabel'], self::ERROR_CODES)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStepLabels(): array
    {
        return [
            'agents'          => __('Agents', 'grcmanager'),
            'threats'         => __('Threats', 'grcmanager'),
            'alerts'          => __('Alerts', 'grcmanager'),
            'vulnerabilities' => __('Application vulnerabilities', 'grcmanager'),
        ];
    }

    /**
     * Libellés des deux sortes de propositions.
     *
     * @return array<string, string>
     */
    public static function getKindLabels(): array
    {
        return [
            'threat' => __('Threat', 'grcmanager'),
            'alert'  => __('Alert', 'grcmanager'),
        ];
    }
}

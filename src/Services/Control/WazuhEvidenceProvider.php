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

namespace GlpiPlugin\Grcmanager\Services\Control;

use Computer;
use GlpiPlugin\Grcmanager\ParkRemediation;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilityRules;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

/**
 * Issue #368 : lecture seule des tables du plugin Wazuh (voir WazuhEvidence). Toute absence (plugin
 * inactif, table ou colonne manquante, erreur de lecture) donne null : l'appelant n'affiche rien.
 */
final class WazuhEvidenceProvider
{
    /** Issue #1185 : ordinateurs nommés au plus par CVE, et ordinateurs critiques listés. */
    public const MAX_COMPUTERS_PER_CVE = 5;
    public const MAX_CRITICAL_SHOWN = 50;

    /** Table des agents utilisable, ou null si le plugin Wazuh n'est pas exploitable. */
    public static function agentTable(): ?string
    {
        global $DB;

        if (!\Plugin::isPluginActive(WazuhEvidence::PLUGIN_KEY)) {
            return null;
        }

        return WazuhEvidence::resolveTable(
            WazuhEvidence::AGENT_TABLES,
            WazuhEvidence::AGENT_COLUMNS,
            static fn (string $table): bool => $DB->tableExists($table),
            static fn (string $table, string $column): bool => $DB->fieldExists($table, $column)
        );
    }

    public static function isAvailable(): bool
    {
        return self::agentTable() !== null;
    }

    /**
     * Couverture Wazuh du parc (ordinateurs des entités actives de l'utilisateur), ou null.
     *
     * @return array{agents: int, active: int, covered: int, computers: int, rate: float|null}|null
     */
    public static function fetchCoverage(): ?array
    {
        global $DB;

        $agents = self::agentTable();
        if ($agents === null) {
            return null;
        }
        try {
            $total = 0;
            $active = 0;
            $covered = [];
            $activeIds = [];
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'status', 'itemtype', 'item_id'],
                    'FROM'   => $agents,
                    'WHERE'  => ['is_deleted' => 0],
                ]) as $row
            ) {
                $total++;
                if ((string) $row['status'] !== WazuhEvidence::ACTIVE_STATUS) {
                    continue;
                }
                $active++;
                if ((string) $row['itemtype'] === Computer::class && (int) $row['item_id'] > 0) {
                    $covered[(int) $row['item_id']] = true;
                }
                $activeIds[] = (int) $row['id'];
            }
            // Rattachements supplémentaires des agents actifs, si la table existe.
            $relation = WazuhEvidence::resolveTable(
                [WazuhEvidence::RELATION_TABLE],
                WazuhEvidence::RELATION_COLUMNS,
                static fn (string $table): bool => $DB->tableExists($table),
                static fn (string $table, string $column): bool => $DB->fieldExists($table, $column)
            );
            if ($relation !== null && $activeIds !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['items_id'],
                        'FROM'   => $relation,
                        'WHERE'  => ['itemtype' => Computer::class, 'pluginwazuhagent_id' => $activeIds],
                    ]) as $row
                ) {
                    $covered[(int) $row['items_id']] = true;
                }
            }

            $computerIds = self::visibleComputerIds();
        } catch (\Throwable $e) {
            return null;
        }

        return WazuhEvidence::coverage(
            $total,
            $active,
            count(array_intersect_key($covered, $computerIds)),
            count($computerIds)
        );
    }

    /**
     * Ordinateurs réels et visibles de l'utilisateur (entités actives), par id. Issue #707 : aussi
     * utilisé par le connecteur SentinelOne (même cloisonnement par entité).
     *
     * @return array<int, true>
     */
    public static function visibleComputerIds(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Computer::getTable(),
                'WHERE'  => ['is_deleted' => 0, 'is_template' => 0]
                    + \getEntitiesRestrictCriteria(Computer::getTable(), '', '', true),
            ]) as $row
        ) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    /**
     * Issue #368 : vulnérabilités que Wazuh voit sur les ordinateurs visibles, regroupées sans doublon
     * avec la veille NVD (voir WazuhEvidence::aggregateVulnerabilities()), enrichies des signaux KEV et
     * EPSS déjà connus du plugin. Null si le plugin Wazuh ou sa table manque.
     *
     * Issue #1185 : `computers` donne les ordinateurs touchés par chaque CVE de `only` (nom et
     * lien, les premiers seulement) et `critical` les ordinateurs avec une vulnérabilité critique non
     * traitée (voir untreatedCritical()).
     *
     * @param array<string, true> $knownCves CVE déjà listées par la veille
     * @return array{also: array<string, int>, only: list<array{cve_id: string, packages: list<string>,
     *     machines: int, score: float|null, severity: string, is_kev: bool, epss: float|null,
     *     priority: bool}>, computers: array<string, list<array{name: string, url: string}>>,
     *     critical: list<array{name: string, url: string, cves: list<string>}>, critical_total: int}|null
     */
    public static function fetchVulnerabilities(array $knownCves): ?array
    {
        try {
            $findings = self::readFindings(array_keys(self::visibleComputerIds()));
            if ($findings === null) {
                return null;
            }
            $result = WazuhEvidence::aggregateVulnerabilities($findings, $knownCves);
            $critical = WazuhEvidence::untreatedCritical($findings, self::waivedCves($findings));
            // Noms des ordinateurs seulement avec la lecture native des ordinateurs : sinon, les
            // nombres restent, sans nom ni lien.
            $canSeeComputers = (bool) Computer::canView();
            $shown = $canSeeComputers ? array_slice($critical, 0, self::MAX_CRITICAL_SHOWN, true) : [];
            $wanted = array_keys($shown);
            foreach ($canSeeComputers ? $result['computers'] : [] as $ids) {
                $wanted = array_merge($wanted, array_slice($ids, 0, self::MAX_COMPUTERS_PER_CVE));
            }
            $names = self::computerNames($wanted);
            $link = static fn (int $id): array => [
                'name' => $names[$id] ?? (string) $id,
                'url'  => Computer::getFormURLWithID($id),
            ];
            $computers = [];
            foreach ($canSeeComputers ? $result['computers'] : [] as $cve => $ids) {
                $computers[$cve] = array_map($link, array_slice($ids, 0, self::MAX_COMPUTERS_PER_CVE));
            }
            $criticalRows = [];
            foreach ($shown as $id => $cves) {
                $criticalRows[] = $link($id) + ['cves' => $cves];
            }

            return [
                'also'           => $result['also'],
                'only'           => self::withExploitSignals($result['only']),
                'computers'      => $computers,
                'critical'       => $criticalRows,
                'critical_total' => count($critical),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Issue #1185 : lignes de vulnérabilités Wazuh toujours signalées sur les ordinateurs donnés.
     * Null si le plugin Wazuh ou sa table manque.
     *
     * @param list<int> $computerIds
     * @return list<array{cve: string, computers_id: int, score: float|null, severity: string,
     *     package: string, version: string}>|null
     */
    private static function readFindings(array $computerIds): ?array
    {
        global $DB;

        if (self::agentTable() === null) {
            return null;
        }
        $table = WazuhEvidence::resolveTable(
            WazuhEvidence::VULNERABILITY_TABLES,
            WazuhEvidence::VULNERABILITY_COLUMNS,
            static fn (string $name): bool => $DB->tableExists($name),
            static fn (string $name, string $column): bool => $DB->fieldExists($name, $column)
        );
        if ($table === null) {
            return null;
        }
        $findings = [];
        if ($computerIds === []) {
            return $findings;
        }
        foreach (
            $DB->request([
                'SELECT' => ['name', 'computers_id', 'v_severity', 'v_score', 'p_name', 'p_version'],
                'FROM'   => $table,
                'WHERE'  => [
                    'is_deleted'     => 0,
                    'is_discontinue' => 0,
                    'computers_id'   => $computerIds,
                ],
            ]) as $row
        ) {
            $findings[] = [
                'cve'          => (string) $row['name'],
                'computers_id' => (int) $row['computers_id'],
                'score'        => $row['v_score'] !== null && (float) $row['v_score'] > 0
                    ? (float) $row['v_score'] : null,
                'severity'     => (string) ($row['v_severity'] ?? ''),
                'package'      => (string) ($row['p_name'] ?? ''),
                'version'      => (string) ($row['p_version'] ?? ''),
            ];
        }

        return $findings;
    }

    /**
     * CVE critiques des lignes données couvertes par une dérogation validée en cours (voir
     * ParkRemediation::waiversByCve()).
     *
     * @param list<array{cve: string, computers_id: int, score: float|null, severity: string}> $findings
     * @return array<string, true>
     */
    private static function waivedCves(array $findings): array
    {
        global $DB;

        $cves = [];
        foreach ($findings as $finding) {
            if (WazuhEvidence::isCritical($finding['score'], $finding['severity'])) {
                $cves[strtoupper(trim($finding['cve']))] = true;
            }
        }
        if ($cves === [] || !$DB->tableExists(ParkRemediation::WAIVER_CVES_TABLE)) {
            return [];
        }
        $today = date('Y-m-d');
        $waived = [];
        foreach (ParkRemediation::waiversByCve(array_keys($cves)) as $cve => $waiver) {
            if ($waiver['date_end'] >= $today) {
                $waived[$cve] = true;
            }
        }

        return $waived;
    }

    /**
     * Noms des ordinateurs donnés, par id.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function computerNames(array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        $request = ['SELECT' => ['id', 'name'], 'FROM' => Computer::getTable(), 'WHERE' => ['id' => $ids]];
        foreach ($DB->request($request) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Issue #1185 : nombre d'ordinateurs avec une vulnérabilité critique non traitée, sur un périmètre
     * d'entités (source de KRI, sans session) ; null si le plugin Wazuh ou sa table manque.
     *
     * @param list<int> $perimeter
     */
    public static function untreatedCriticalComputers(array $perimeter): ?float
    {
        global $DB;

        try {
            $ids = [];
            if ($perimeter !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => Computer::getTable(),
                        'WHERE'  => ['is_deleted' => 0, 'is_template' => 0, 'entities_id' => $perimeter],
                    ]) as $row
                ) {
                    $ids[] = (int) $row['id'];
                }
            }
            $findings = self::readFindings($ids);

            return $findings === null
                ? null
                : (float) count(WazuhEvidence::untreatedCritical($findings, self::waivedCves($findings)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Issue #1185 : carte du tableau de bord (WazuhVulnerabilityFeature). Un tableau de bord est
     * partagé : sans le droit sur les vulnérabilités du parc et la lecture des ordinateurs, la carte
     * garde sa forme mais n'affiche rien.
     *
     * @param array<string, mixed> $params
     * @return array{number: int, url: string, label: string, icon: string}
     */
    public static function criticalComputersCard(array $params = []): array
    {
        $allowed = ParkVulnerability::canView() && Computer::canView();
        $count = $allowed ? self::untreatedCriticalVisible() : null;

        return [
            'number' => $count ?? 0,
            'url'    => $allowed ? ParkVulnerability::getSearchURL() . '#grc-wazuh-critical' : '',
            'label'  => (string) ($params['label']
                ?? __('Computers with untreated critical vulnerabilities (Wazuh)', 'grcmanager')),
            'icon'   => 'ti ti-heartbeat',
        ];
    }

    /**
     * Issue #1185 : même nombre pour les ordinateurs visibles de l'utilisateur (carte du tableau de
     * bord) ; null si le plugin Wazuh ou sa table manque.
     */
    public static function untreatedCriticalVisible(): ?int
    {
        try {
            $findings = self::readFindings(array_keys(self::visibleComputerIds()));

            return $findings === null
                ? null
                : count(WazuhEvidence::untreatedCritical($findings, self::waivedCves($findings)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Signaux d'exploitation déjà collectés par la veille (KEV, EPSS) et priorité, pour les lignes
     * d'une source externe (Wazuh, SentinelOne : issue #707), avec la même priorisation.
     *
     * @param list<array{cve_id: string, packages: list<string>, machines: int, score: float|null,
     *     severity: string}> $rows
     * @return list<array{cve_id: string, packages: list<string>, machines: int, score: float|null,
     *     severity: string, is_kev: bool, epss: float|null, priority: bool}>
     */
    public static function withExploitSignals(array $rows): array
    {
        global $DB;

        $signals = [];
        $cves = array_column($rows, 'cve_id');
        if ($cves !== [] && $DB->tableExists(ParkVulnerabilitySync::SIGNALS_TABLE)) {
            foreach (
                $DB->request([
                    'SELECT' => ['cve_id', 'is_kev', 'epss'],
                    'FROM'   => ParkVulnerabilitySync::SIGNALS_TABLE,
                    'WHERE'  => ['cve_id' => $cves],
                ]) as $row
            ) {
                $signals[(string) $row['cve_id']] = $row;
            }
        }
        $only = [];
        foreach ($rows as $row) {
            $signal = $signals[$row['cve_id']] ?? null;
            $isKev = (bool) ($signal['is_kev'] ?? false);
            $epss = isset($signal['epss']) ? (float) $signal['epss'] : null;
            $only[] = $row + [
                'is_kev'   => $isKev,
                'epss'     => $epss,
                'priority' => ParkVulnerabilityRules::isPriority($row['score'], $isKev, $epss),
            ];
        }

        return $only;
    }

    /**
     * Issue #368 : alertes Wazuh récentes des ordinateurs donnés (actifs liés à un risque, déjà
     * filtrés sur les droits de l'utilisateur), les plus récentes d'abord. Null si le plugin Wazuh
     * ou sa table des alertes manque.
     *
     * @param list<int> $computerIds
     * @return list<array{date: string, computers_id: int, level: int|null, severity: string,
     *     description: string, rule_id: string}>|null
     */
    public static function fetchRecentAlerts(array $computerIds, string $since): ?array
    {
        global $DB;

        if (self::agentTable() === null) {
            return null;
        }
        $table = WazuhEvidence::resolveTable(
            WazuhEvidence::ALERT_TABLES,
            WazuhEvidence::ALERT_COLUMNS,
            static fn (string $name): bool => $DB->tableExists($name),
            static fn (string $name, string $column): bool => $DB->fieldExists($name, $column)
        );
        if ($table === null) {
            return null;
        }
        if ($computerIds === []) {
            return [];
        }
        $alerts = [];
        try {
            foreach (
                $DB->request([
                    'SELECT' => ['computers_id', 'rule', 'source_timestamp'],
                    'FROM'   => $table,
                    'WHERE'  => [
                        'is_deleted'   => 0,
                        'computers_id' => $computerIds,
                        ['source_timestamp' => ['>=', $since]],
                    ],
                    'ORDER'  => ['source_timestamp DESC'],
                    'LIMIT'  => WazuhEvidence::MAX_ALERTS,
                ]) as $row
            ) {
                $rule = WazuhEvidence::parseRule($row['rule'] !== null ? (string) $row['rule'] : null);
                $alerts[] = [
                    'date'         => (string) $row['source_timestamp'],
                    'computers_id' => (int) $row['computers_id'],
                    'level'        => $rule['level'],
                    'severity'     => WazuhEvidence::alertSeverity($rule['level']),
                    'description'  => $rule['description'],
                    'rule_id'      => $rule['id'],
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $alerts;
    }

    /**
     * Issue #1185 : alertes Wazuh des ordinateurs donnés (déjà filtrés sur les entités et droits de
     * l'utilisateur) depuis $since, les plus récentes d'abord, pour la revue des alertes. Les lignes
     * de regroupement du plugin Wazuh (sans horodatage) sont écartées. Null si le plugin Wazuh, sa
     * table des alertes ou l'une des colonnes lues manque.
     *
     * @param list<int> $computerIds
     * @return list<array{id: int, key: string, computers_id: int, agent: string, date: string,
     *     level: int|null, description: string, rule_id: string, techniques: list<string>}>|null
     */
    public static function fetchReviewAlerts(array $computerIds, string $since): ?array
    {
        global $DB;

        if (self::agentTable() === null) {
            return null;
        }
        $table = WazuhEvidence::resolveTable(
            WazuhEvidence::ALERT_TABLES,
            array_merge(WazuhEvidence::ALERT_COLUMNS, WazuhEvidence::ALERT_REVIEW_COLUMNS),
            static fn (string $name): bool => $DB->tableExists($name),
            static fn (string $name, string $column): bool => $DB->fieldExists($name, $column)
        );
        if ($table === null) {
            return null;
        }
        if ($computerIds === []) {
            return [];
        }
        $alerts = [];
        try {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'key', 'computers_id', 'a_name', 'rule', 'source_timestamp'],
                    'FROM'   => $table,
                    'WHERE'  => [
                        'is_deleted'   => 0,
                        'computers_id' => $computerIds,
                        ['source_timestamp' => ['>=', $since]],
                    ],
                    'ORDER'  => ['source_timestamp DESC', 'id DESC'],
                    'LIMIT'  => WazuhEvidence::MAX_REVIEW_ALERTS,
                ]) as $row
            ) {
                $json = $row['rule'] !== null ? (string) $row['rule'] : null;
                $rule = WazuhEvidence::parseRule($json);
                $alerts[] = [
                    'id'           => (int) $row['id'],
                    'key'          => (string) $row['key'],
                    'computers_id' => (int) $row['computers_id'],
                    'agent'        => (string) ($row['a_name'] ?? ''),
                    'date'         => (string) $row['source_timestamp'],
                    'level'        => $rule['level'],
                    'description'  => $rule['description'],
                    'rule_id'      => $rule['id'],
                    'techniques'   => WazuhEvidence::ruleTechniques($json),
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $alerts;
    }

    /**
     * Issue #1185 : lien vers les alertes Wazuh d'un ordinateur (onglet du plugin Wazuh sur la fiche
     * de l'ordinateur ; le plugin n'a pas de page propre par alerte).
     */
    public static function alertsTabUrl(int $computerId): string
    {
        return Computer::getFormURLWithID($computerId) . '&forcetab='
            . rawurlencode('GlpiPlugin\\Wazuh\\ComputerAlertsTab$1');
    }

    /** Liste des agents dans le plugin Wazuh, si sa classe expose une recherche. */
    public static function agentsUrl(): ?string
    {
        // Classe du plugin Wazuh, optionnelle : appelée seulement si elle est chargée.
        $search = ['GlpiPlugin\\Wazuh\\WazuhAgent', 'getSearchURL'];

        return is_callable($search) ? (string) call_user_func($search) : null;
    }
}

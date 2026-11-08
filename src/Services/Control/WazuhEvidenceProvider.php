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
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilityRules;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

/**
 * Issue #368 : lecture seule des tables du plugin Wazuh (voir WazuhEvidence). Toute absence (plugin
 * inactif, table ou colonne manquante, erreur de lecture) donne null : l'appelant n'affiche rien.
 */
final class WazuhEvidenceProvider
{
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
     * Ordinateurs réels et visibles de l'utilisateur (entités actives), par id.
     *
     * @return array<int, true>
     */
    private static function visibleComputerIds(): array
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
     * @param array<string, true> $knownCves CVE déjà listées par la veille
     * @return array{also: array<string, int>, only: list<array{cve_id: string, packages: list<string>,
     *     machines: int, score: float|null, severity: string, is_kev: bool, epss: float|null,
     *     priority: bool}>}|null
     */
    public static function fetchVulnerabilities(array $knownCves): ?array
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
        try {
            $visible = self::visibleComputerIds();
            $findings = [];
            if ($visible !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['name', 'computers_id', 'v_severity', 'v_score', 'p_name', 'p_version'],
                        'FROM'   => $table,
                        'WHERE'  => [
                            'is_deleted'     => 0,
                            'is_discontinue' => 0,
                            'computers_id'   => array_keys($visible),
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
            }
            $result = WazuhEvidence::aggregateVulnerabilities($findings, $knownCves);

            // Signaux d'exploitation déjà collectés par la veille (KEV, EPSS), pour la même priorisation.
            $signals = [];
            $cves = array_column($result['only'], 'cve_id');
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
        } catch (\Throwable $e) {
            return null;
        }
        $only = [];
        foreach ($result['only'] as $row) {
            $signal = $signals[$row['cve_id']] ?? null;
            $isKev = (bool) ($signal['is_kev'] ?? false);
            $epss = isset($signal['epss']) ? (float) $signal['epss'] : null;
            $only[] = $row + [
                'is_kev'   => $isKev,
                'epss'     => $epss,
                'priority' => ParkVulnerabilityRules::isPriority($row['score'], $isKev, $epss),
            ];
        }

        return ['also' => $result['also'], 'only' => $only];
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

    /** Liste des agents dans le plugin Wazuh, si sa classe expose une recherche. */
    public static function agentsUrl(): ?string
    {
        // Classe du plugin Wazuh, optionnelle : appelée seulement si elle est chargée.
        $search = ['GlpiPlugin\\Wazuh\\WazuhAgent', 'getSearchURL'];

        return is_callable($search) ? (string) call_user_func($search) : null;
    }
}

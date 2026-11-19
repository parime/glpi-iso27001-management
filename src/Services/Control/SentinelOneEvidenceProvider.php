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

use GlpiPlugin\Grcmanager\SentinelOneAlert;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;

/**
 * Issue #707 : données déjà lues dans SentinelOne par la tâche automatique (jamais d'appel à la
 * console à l'affichage d'une page). Mêmes modèles que le pont Wazuh (#368) : couverture du parc
 * pour les mesures A.8.16 et A.8.7, vulnérabilités applicatives regroupées sans doublon avec la
 * veille. On ne voit que les ordinateurs de ses entités actives. Null si le connecteur n'a encore
 * rien lu (ou n'est pas configuré) : l'appelant n'affiche rien.
 */
final class SentinelOneEvidenceProvider
{
    public static function hasData(): bool
    {
        global $DB;

        return SentinelOneConfig::isConfigured()
            && $DB->tableExists(SentinelOneAlert::AGENTS_TABLE)
            && SentinelOneConfig::lastRun() !== null;
    }

    /**
     * Couverture SentinelOne du parc visible, au format de WazuhEvidence::coverage().
     *
     * @return array{agents: int, active: int, covered: int, computers: int, rate: float|null}|null
     */
    public static function fetchCoverage(): ?array
    {
        global $DB;

        if (!self::hasData()) {
            return null;
        }
        try {
            $total = 0;
            $active = 0;
            $covered = [];
            foreach ($DB->request(['SELECT' => ['is_active', 'computers_id'], 'FROM' => SentinelOneAlert::AGENTS_TABLE]) as $row) {
                $total++;
                if ((int) $row['is_active'] !== 1) {
                    continue;
                }
                $active++;
                if ((int) $row['computers_id'] > 0) {
                    $covered[(int) $row['computers_id']] = true;
                }
            }
            $computerIds = WazuhEvidenceProvider::visibleComputerIds();
        } catch (\Throwable $e) {
            return null;
        }

        return WazuhEvidence::coverage($total, $active, count(array_intersect_key($covered, $computerIds)), count($computerIds));
    }

    /**
     * Vulnérabilités applicatives vues par SentinelOne sur les ordinateurs visibles, sans doublon
     * avec la veille (voir WazuhEvidence::aggregateVulnerabilities()), avec KEV et EPSS.
     *
     * @param array<string, true> $knownCves CVE déjà listées par la veille
     * @return array{also: array<string, int>, only: list<array{cve_id: string, packages: list<string>,
     *     machines: int, score: float|null, severity: string, is_kev: bool, epss: float|null,
     *     priority: bool}>, updated: string}|null
     */
    public static function fetchVulnerabilities(array $knownCves): ?array
    {
        global $DB;

        if (!self::hasData() || !$DB->tableExists(SentinelOneAlert::VULNERABILITIES_TABLE)) {
            return null;
        }
        try {
            $visible = WazuhEvidenceProvider::visibleComputerIds();
            $findings = [];
            $updated = '';
            if ($visible !== []) {
                foreach (
                    $DB->request([
                        'SELECT' => ['cve_id', 'computers_id', 'score', 'severity', 'application', 'version', 'date_mod'],
                        'FROM'   => SentinelOneAlert::VULNERABILITIES_TABLE,
                        'WHERE'  => ['computers_id' => array_keys($visible)],
                    ]) as $row
                ) {
                    $findings[] = [
                        'cve'          => (string) $row['cve_id'],
                        'computers_id' => (int) $row['computers_id'],
                        'score'        => $row['score'] !== null ? (float) $row['score'] : null,
                        'severity'     => (string) $row['severity'],
                        'package'      => (string) $row['application'],
                        'version'      => (string) $row['version'],
                    ];
                    $updated = max($updated, (string) $row['date_mod']);
                }
            }
            if ($findings === [] && $DB->request(['FROM' => SentinelOneAlert::VULNERABILITIES_TABLE, 'LIMIT' => 1])->count() === 0) {
                // Rien lu (licence sans ce module) : pas de section vide trompeuse.
                return null;
            }
            $result = WazuhEvidence::aggregateVulnerabilities($findings, $knownCves);

            return [
                'also'    => $result['also'],
                'only'    => WazuhEvidenceProvider::withExploitSignals($result['only']),
                'updated' => $updated,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}

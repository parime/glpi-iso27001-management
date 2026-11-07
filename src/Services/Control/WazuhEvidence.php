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

/**
 * Issue #368 : pont optionnel avec le plugin GLPI Wazuh (initiativa/wazuh, bêta, GLPI 11). Règles
 * pures ; la lecture des tables est dans WazuhEvidenceProvider.
 *
 * Le plugin Wazuh est en bêta et son schéma a déjà varié (le nom de la table des agents dépend de
 * la version) : chaque table a plusieurs noms possibles et les colonnes lues sont vérifiées avant
 * toute requête. Si quoi que ce soit manque, le pont se tait : rien n'est affiché, rien ne casse.
 * Lecture seule, jamais d'écriture dans les tables du plugin Wazuh.
 */
final class WazuhEvidence
{
    public const PLUGIN_KEY = 'wazuh';

    /** Noms possibles de la table des agents, du plus récent au plus ancien. */
    public const AGENT_TABLES = ['glpi_plugin_wazuh_wazuhagents', 'glpi_plugin_wazuh_pluginwazuhagents'];
    public const AGENT_COLUMNS = ['status', 'itemtype', 'item_id', 'is_deleted'];

    /** Rattachements supplémentaires agent -> matériel (un agent peut couvrir plusieurs éléments). */
    public const RELATION_TABLE = 'glpi_plugin_wazuh_agentassets';
    public const RELATION_COLUMNS = ['pluginwazuhagent_id', 'itemtype', 'items_id'];

    /** Statut Wazuh d'un agent qui remonte des données. */
    public const ACTIVE_STATUS = 'active';

    /** Mesures dont la couverture Wazuh est une preuve : surveillance et protection anti-malware. */
    public const CONTROLS = ['A.8.16', 'A.8.7'];

    public static function isRelevantControl(string $code): bool
    {
        return in_array($code, self::CONTROLS, true);
    }

    /**
     * Première table existante parmi les noms possibles, dont toutes les colonnes requises sont là.
     *
     * @param list<string> $candidates
     * @param list<string> $columns
     * @param callable(string): bool $tableExists
     * @param callable(string, string): bool $fieldExists
     */
    public static function resolveTable(
        array $candidates,
        array $columns,
        callable $tableExists,
        callable $fieldExists
    ): ?string {
        foreach ($candidates as $table) {
            if (!$tableExists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!$fieldExists($table, $column)) {
                    return null;
                }
            }

            return $table;
        }

        return null;
    }

    /**
     * Couverture : agents (actifs / total) et ordinateurs couverts par un agent actif.
     *
     * @return array{agents: int, active: int, covered: int, computers: int, rate: float|null}
     */
    public static function coverage(int $agents, int $active, int $covered, int $computers): array
    {
        return [
            'agents'    => $agents,
            'active'    => $active,
            'covered'   => min($covered, $computers),
            'computers' => $computers,
            'rate'      => $computers > 0 ? round(100 * min($covered, $computers) / $computers, 1) : null,
        ];
    }
}

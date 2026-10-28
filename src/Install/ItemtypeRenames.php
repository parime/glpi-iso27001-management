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

namespace GlpiPlugin\Grcmanager\Install;

use Migration;

/**
 * Issue #293, étape 2 : classes déplacées de inc/ (PluginGrcmanagerX, convention historique) vers
 * src/ (GlpiPlugin\Grcmanager\X, PSR-4), famille par famille.
 *
 * - Alias : l'ancien nom reste utilisable (code d'un autre plugin, par exemple
 *   Configuration-glpi-auto), créé seulement à la première utilisation.
 * - Migration : les noms de classe stockés en base deviennent les nouveaux. Les tables gardent leur
 *   nom (getTableForItemType() donne glpi_plugin_grcmanager_xs dans les deux cas).
 */
final class ItemtypeRenames
{
    /** @var array<string, class-string> Ancien nom => nouveau nom. */
    public const MAP = [
        'PluginGrcmanagerWaiver'                   => \GlpiPlugin\Grcmanager\Waiver::class,
        'PluginGrcmanagerNotificationTargetWaiver' => \GlpiPlugin\Grcmanager\NotificationTargetWaiver::class,
        // Conformité : politiques, obligations, calendrier, preuves.
        'PluginGrcmanagerPolicy'                   => \GlpiPlugin\Grcmanager\Policy::class,
        'PluginGrcmanagerNotificationTargetPolicy' => \GlpiPlugin\Grcmanager\NotificationTargetPolicy::class,
        'PluginGrcmanagerComplianceObligation'     => \GlpiPlugin\Grcmanager\ComplianceObligation::class,
        'PluginGrcmanagerNotificationTargetComplianceObligation'
            => \GlpiPlugin\Grcmanager\NotificationTargetComplianceObligation::class,
        'PluginGrcmanagerComplianceEvent'          => \GlpiPlugin\Grcmanager\ComplianceEvent::class,
        'PluginGrcmanagerNotificationTargetComplianceEvent'
            => \GlpiPlugin\Grcmanager\NotificationTargetComplianceEvent::class,
        'PluginGrcmanagerEvidence'                 => \GlpiPlugin\Grcmanager\Evidence::class,
        'PluginGrcmanagerNotificationTargetEvidence' => \GlpiPlugin\Grcmanager\NotificationTargetEvidence::class,
        // Risques : registre, plan de traitement, fournisseurs, analyse d'impact, liens de causalité.
        'PluginGrcmanagerRisk'                     => \GlpiPlugin\Grcmanager\Risk::class,
        'PluginGrcmanagerNotificationTargetRisk'   => \GlpiPlugin\Grcmanager\NotificationTargetRisk::class,
        'PluginGrcmanagerRiskTreatmentAction'      => \GlpiPlugin\Grcmanager\RiskTreatmentAction::class,
        'PluginGrcmanagerNotificationTargetRiskTreatmentAction'
            => \GlpiPlugin\Grcmanager\NotificationTargetRiskTreatmentAction::class,
        'PluginGrcmanagerSupplierRisk'             => \GlpiPlugin\Grcmanager\SupplierRisk::class,
        'PluginGrcmanagerNotificationTargetSupplierRisk'
            => \GlpiPlugin\Grcmanager\NotificationTargetSupplierRisk::class,
        'PluginGrcmanagerSupplierAssessment'       => \GlpiPlugin\Grcmanager\SupplierAssessment::class,
        'PluginGrcmanagerImpactAnalysis'           => \GlpiPlugin\Grcmanager\ImpactAnalysis::class,
        'PluginGrcmanagerCausalLink'               => \GlpiPlugin\Grcmanager\CausalLink::class,
        // Audits : audits internes, équipe d'audit, audit technique, non-conformités, revue de direction.
        'PluginGrcmanagerAudit'                    => \GlpiPlugin\Grcmanager\Audit::class,
        'PluginGrcmanagerAuditTeam'                => \GlpiPlugin\Grcmanager\AuditTeam::class,
        'PluginGrcmanagerComplianceAudit'          => \GlpiPlugin\Grcmanager\ComplianceAudit::class,
        'PluginGrcmanagerNonconformity'            => \GlpiPlugin\Grcmanager\Nonconformity::class,
        'PluginGrcmanagerNotificationTargetNonconformity'
            => \GlpiPlugin\Grcmanager\NotificationTargetNonconformity::class,
        'PluginGrcmanagerManagementReview'         => \GlpiPlugin\Grcmanager\ManagementReview::class,
    ];

    /**
     * Colonnes qui stockent un nom de classe sans commencer par « itemtype » : ignorées par
     * Migration::renameItemtype().
     *
     * @var array<string, string> Table => colonne
     */
    private const EXTRA_COLUMNS = [
        'glpi_dropdownvisibilities'     => 'visible_itemtype',
        'glpi_searches_criteriafilters' => 'search_itemtype',
    ];

    public static function registerAliases(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (isset(self::MAP[$class])) {
                class_alias(self::MAP[$class], $class);
            }
        });
    }

    /**
     * Renomme les types stockés en base (idempotent : sans ligne à l'ancien nom, ne fait rien).
     */
    public static function migrate(Migration $migration): void
    {
        global $DB;

        $uniqueKeys = self::uniqueKeysOnTypeColumns();
        foreach (self::MAP as $old => $new) {
            self::removeOldTwins($uniqueKeys, $old, $new);
            $migration->renameItemtype($old, $new, false);
            foreach (self::EXTRA_COLUMNS as $table => $column) {
                if ($DB->tableExists($table) && $DB->fieldExists($table, $column)) {
                    $DB->update($table, [$column => $new], [$column => $old]);
                }
            }
            // Recherches enregistrées : le type figure aussi dans la requête (itemtype=...), encodée
            // comme une URL (l'ancien nom n'a aucun caractère à encoder, le nouveau si : « \ »).
            // Nom exact seulement : PluginGrcmanagerRisk ne doit pas toucher à
            // PluginGrcmanagerRiskCatalog, resté dans inc/.
            $exactName = '/' . preg_quote($old, '/') . '(?![A-Za-z0-9_])/';
            if ($DB->tableExists('glpi_savedsearches')) {
                $searches = $DB->request([
                    'SELECT' => ['id', 'query'],
                    'FROM'   => 'glpi_savedsearches',
                    'WHERE'  => ['query' => ['LIKE', '%' . $old . '%']],
                ]);
                foreach ($searches as $row) {
                    $DB->update('glpi_savedsearches', [
                        'query' => preg_replace($exactName, urlencode($new), (string) $row['query']),
                    ], ['id' => $row['id']]);
                }
            }
        }
    }

    /**
     * Clés uniques qui contiennent une colonne de type (préférences d'affichage, tâches
     * planifiées, recherche par défaut…).
     *
     * @return list<array{table: string, column: string, others: list<string>}>
     */
    private static function uniqueKeysOnTypeColumns(): array
    {
        global $DB;

        $keys = [];
        $rows = $DB->request([
            'SELECT' => ['table_name AS t', 'index_name AS i', 'column_name AS c'],
            'FROM'   => 'information_schema.statistics',
            'WHERE'  => [
                'table_schema' => $DB->dbdefault,
                'table_name'   => ['LIKE', 'glpi\_%'],
                'non_unique'   => 0,
                'index_name'   => ['<>', 'PRIMARY'],
            ],
            'ORDER'  => ['t', 'i', 'seq_in_index'],
        ]);
        foreach ($rows as $row) {
            $keys[$row['t'] . '.' . $row['i']]['table'] = (string) $row['t'];
            $keys[$row['t'] . '.' . $row['i']]['columns'][] = (string) $row['c'];
        }

        $result = [];
        foreach ($keys as $key) {
            $table = $key['table'];
            if (!$DB->fieldExists($table, 'id')) {
                continue;
            }
            foreach ($key['columns'] as $column) {
                $isType = $column === 'itemtype' || str_starts_with($column, 'itemtype_')
                    || (self::EXTRA_COLUMNS[$table] ?? null) === $column;
                if ($isType) {
                    $result[] = [
                        'table'  => $table,
                        'column' => $column,
                        'others' => array_values(array_diff($key['columns'], [$column])),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Ligne à l'ancien nom dont la jumelle au nouveau nom existe déjà (même clé unique) : le
     * renommage la heurterait (« Duplicate entry ») et arrêterait la mise à jour. Cas d'un alias :
     * colonnes réglées depuis un ancien favori (itemtype=PluginGrcmanagerX). La ligne au nouveau
     * nom, la plus récente, est gardée ; seule la doublure est retirée.
     *
     * @param list<array{table: string, column: string, others: list<string>}> $uniqueKeys
     */
    private static function removeOldTwins(array $uniqueKeys, string $old, string $new): void
    {
        global $DB;

        foreach ($uniqueKeys as ['table' => $table, 'column' => $column, 'others' => $others]) {
            $oldRows = $DB->request([
                'SELECT' => array_merge(['id'], $others),
                'FROM'   => $table,
                'WHERE'  => [$column => $old],
            ]);
            foreach ($oldRows as $row) {
                $twin = [$column => $new];
                foreach ($others as $other) {
                    $twin[$other] = $row[$other];
                }
                if (countElementsInTable($table, $twin) > 0) {
                    $DB->delete($table, ['id' => $row['id']]);
                }
            }
        }
    }
}

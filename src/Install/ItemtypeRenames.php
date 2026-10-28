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

        foreach (self::MAP as $old => $new) {
            $migration->renameItemtype($old, $new, false);
            foreach (self::EXTRA_COLUMNS as $table => $column) {
                if ($DB->tableExists($table) && $DB->fieldExists($table, $column)) {
                    $DB->update($table, [$column => $new], [$column => $old]);
                }
            }
            // Recherches enregistrées : le type figure aussi dans la requête (itemtype=...).
            if ($DB->tableExists('glpi_savedsearches')) {
                $searches = $DB->request([
                    'SELECT' => ['id', 'query'],
                    'FROM'   => 'glpi_savedsearches',
                    'WHERE'  => ['query' => ['LIKE', '%' . $old . '%']],
                ]);
                foreach ($searches as $row) {
                    $DB->update('glpi_savedsearches', [
                        'query' => str_replace([$old, urlencode($old)], [$new, urlencode($new)], (string) $row['query']),
                    ], ['id' => $row['id']]);
                }
            }
        }
    }
}

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

/**
 * One GLPI software name (the exact string as it appears in `glpi_softwares.name` on this
 * instance's inventory) manually declared as an alias of a PluginGrcmanagerCanonicalProduct —
 * several per product, managed inline on that product's own form (see
 * PluginGrcmanagerCanonicalProduct::showProductAliases()), no menu/search screen of its own, same
 * convention as PluginGrcmanagerObjectiveMeasurement.
 *
 * Matched by GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher by exact string equality only
 * — never a fuzzy/normalized match, same "no invented correlation" principle as the rest of this
 * plugin family.
 *
 * Uses PluginGrcmanagerCanonicalProduct's own right rather than a dedicated one: a product alias
 * has no meaningful access boundary of its own, separate from the product it documents.
 */
class PluginGrcmanagerProductAlias extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_productaliases';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Alias de logiciel', 'Alias de logiciels', $nb, 'grcmanager');
    }

    /**
     * @return list<array{id: int, alias: string}>
     */
    public static function getForCanonicalProduct(int $canonicalProductId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'alias'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_grcmanager_canonicalproducts_id' => $canonicalProductId],
                'ORDER'  => 'alias ASC',
            ]) as $row
        ) {
            $rows[] = ['id' => (int) $row['id'], 'alias' => (string) $row['alias']];
        }

        return $rows;
    }

    /**
     * Resolves a raw GLPI software name to its canonical product id, or null if no alias matches
     * — an unmatched software must be silently skipped by the caller, never guessed at (see class
     * docblock).
     */
    public static function resolveCanonicalProductId(string $softwareName): ?int
    {
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_canonicalproducts_id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['alias' => $softwareName],
            ]) as $row
        ) {
            return (int) $row['plugin_grcmanager_canonicalproducts_id'];
        }

        return null;
    }
}

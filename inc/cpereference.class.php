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
 * One CPE identifier manually declared as belonging to a PluginGrcmanagerCanonicalProduct —
 * several per product, managed inline on that product's own form (see
 * PluginGrcmanagerCanonicalProduct::showCpeReferences()), no menu/search screen of its own, same
 * convention as PluginGrcmanagerObjectiveMeasurement.
 *
 * Uses PluginGrcmanagerCanonicalProduct's own right rather than a dedicated one: a CPE reference
 * has no meaningful access boundary of its own, separate from the product it documents.
 */
class PluginGrcmanagerCpeReference extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_cpereferences';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Identifiant CPE', 'Identifiants CPE', $nb, 'grcmanager');
    }

    /**
     * @return list<array{id: int, cpe: string}>
     */
    public static function getForCanonicalProduct(int $canonicalProductId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'cpe'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_grcmanager_canonicalproducts_id' => $canonicalProductId],
                'ORDER'  => 'cpe ASC',
            ]) as $row
        ) {
            $rows[] = ['id' => (int) $row['id'], 'cpe' => (string) $row['cpe']];
        }

        return $rows;
    }
}

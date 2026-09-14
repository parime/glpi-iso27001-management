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
     * `cpe` is UNIQUE in the database (one CPE identifier can only ever point to one canonical
     * product, see Installer.php) — without this check, a duplicate submission reaches
     * CommonDBTM::add()'s raw INSERT and surfaces as an uncaught RuntimeException (HTTP 500,
     * confirmed live against a real GLPI instance) instead of the normal
     * Session::addMessageAfterRedirect() error flow every other validation failure in this plugin
     * uses.
     */
    public function prepareInputForAdd($input)
    {
        $cpe = trim((string) ($input['cpe'] ?? ''));

        if ($cpe === '') {
            Session::addMessageAfterRedirect(
                __('Veuillez saisir un identifiant CPE.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        global $DB;
        $existing = $DB->request([
            'SELECT' => ['manufacturer', 'product'],
            'FROM'   => PluginGrcmanagerCanonicalProduct::getTable(),
            'INNER JOIN' => [
                self::getTable() => [
                    'FKEY' => [
                        self::getTable() => 'plugin_grcmanager_canonicalproducts_id',
                        PluginGrcmanagerCanonicalProduct::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [self::getTable() . '.cpe' => $cpe],
        ])->current();

        if ($existing !== null) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Cet identifiant CPE est déjà rattaché au produit "%s / %s".', 'grcmanager'),
                    $existing['manufacturer'],
                    $existing['product']
                ),
                false,
                ERROR
            );

            return false;
        }

        $input['cpe'] = $cpe;

        return $input;
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

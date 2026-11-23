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

use Config;
use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig;
use Migration;

/**
 * Issue #618 : délégation des décisions pendant une absence. Journal des décisions prises par un
 * suppléant, et suppléant qui a répondu sur les réponses aux versions de la SoA et des politiques
 * et sur les lignes de revue des accès (pour afficher « par X pour le compte de Y »).
 *
 * Les colonnes ajoutées aux tables existantes partent avec elles à la désinstallation
 * (Installer::uninstall()) ; le journal et le réglage sont supprimés ici.
 */
final class DelegationTables implements FeatureTables
{
    public const SOA_ANSWERS = 'glpi_plugin_grcmanager_soaversions_users';
    public const POLICY_ANSWERS = 'glpi_plugin_grcmanager_policyversions_users';
    public const ACCESS_LINES = 'glpi_plugin_grcmanager_accessreviews_users';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(Delegation::TABLE)) {
            $DB->doQuery("CREATE TABLE `" . Delegation::TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite de l element decide',
                `decision_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'access_review, soa_approval, policy_approval',
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `item_label` varchar(255) NOT NULL DEFAULT '' COMMENT 'Libelle fige au moment de la decision',
                `decision` varchar(32) NOT NULL DEFAULT '',
                `users_id_holder` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Titulaire absent',
                `users_id_substitute` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Suppleant qui a decide',
                `date_start` timestamp NULL DEFAULT NULL COMMENT 'Debut de l absence declaree',
                `date_end` timestamp NULL DEFAULT NULL COMMENT 'Fin de l absence declaree',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `users_id_holder` (`users_id_holder`),
                KEY `users_id_substitute` (`users_id_substitute`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        foreach ([self::SOA_ANSWERS, self::POLICY_ANSWERS] as $table) {
            if ($DB->tableExists($table) && !$DB->fieldExists($table, 'users_id_substitute')) {
                $migration->addField($table, 'users_id_substitute', 'fkey', ['after' => 'users_id']);
                $migration->addKey($table, 'users_id_substitute');
                $migration->migrationOneTable($table);
            }
        }
        if ($DB->tableExists(self::ACCESS_LINES) && !$DB->fieldExists(self::ACCESS_LINES, 'is_delegated')) {
            $migration->addField(self::ACCESS_LINES, 'is_delegated', 'bool', ['after' => 'decided_by']);
            $migration->migrationOneTable(self::ACCESS_LINES);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(Delegation::TABLE);
        Config::deleteConfigurationValues(DelegationConfig::CONTEXT, [DelegationConfig::TYPES_KEY]);
    }

    public static function pluginClasses(): array
    {
        return [Delegation::class];
    }
}

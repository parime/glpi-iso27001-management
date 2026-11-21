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
 * Issue #843, lot 2 : comptes locaux des contrôleurs de gestion des serveurs figés dans une
 * campagne de revue des accès (copie du compte au lancement : le contrôleur peut être relu ou
 * supprimé ensuite sans changer la revue). Créée si absente (rejouable), supprimée à la
 * désinstallation, découverte par l'installeur (FeatureTablesRegistry).
 */
final class AccessReviewBmcTables implements FeatureTables
{
    public const LINES = 'glpi_plugin_grcmanager_accessreviews_bmcaccounts';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::LINES)) {
            $DB->doQuery("CREATE TABLE `" . self::LINES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_accessreviews_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_bmccontrollers_id` int {$keySign} NOT NULL DEFAULT 0,
                `controller_name` varchar(255) NOT NULL DEFAULT '',
                `computers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Serveur connu au lancement',
                `username` varchar(255) NOT NULL DEFAULT '',
                `role` varchar(64) NOT NULL DEFAULT '',
                `is_enabled` tinyint NOT NULL DEFAULT 1,
                `is_locked` tinyint NOT NULL DEFAULT 0,
                `suggestion` varchar(16) NOT NULL DEFAULT '',
                `reasons` text COMMENT 'Codes des raisons (JSON)',
                `decision` varchar(16) NOT NULL DEFAULT 'pending',
                `comment` text,
                `decided_by` int {$keySign} NOT NULL DEFAULT 0,
                `date_decision` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_accessreviews_id` (`plugin_grcmanager_accessreviews_id`),
                KEY `plugin_grcmanager_bmccontrollers_id` (`plugin_grcmanager_bmccontrollers_id`),
                KEY `decision` (`decision`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::LINES);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

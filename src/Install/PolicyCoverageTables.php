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
 * Issue #879 : liens entre un texte de la bibliothèque des politiques (la politique entière, ou une
 * de ses sections nommée librement) et une mesure de la SoA d'une entité. Créée si absente
 * (rejouable), supprimée à la désinstallation.
 */
final class PolicyCoverageTables implements FeatureTables
{
    public const LINKS = 'glpi_plugin_grcmanager_policies_controls';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::LINKS)) {
            $DB->doQuery("CREATE TABLE `" . self::LINKS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_policies_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL DEFAULT 0,
                `section` varchar(255) NOT NULL DEFAULT '' COMMENT 'Section du texte, vide : texte entier',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Personne qui a cree le lien',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_policies_id`, `plugin_grcmanager_controls_id`, `section`),
                KEY `plugin_grcmanager_controls_id` (`plugin_grcmanager_controls_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::LINKS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

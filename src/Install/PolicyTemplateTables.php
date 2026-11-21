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
 * Issue #545 : trace des politiques créées depuis un modèle du catalogue (modèle, langue, personne,
 * date). Une politique importée n'est jamais remplacée : chaque import crée un nouveau brouillon.
 * Créée si absente (rejouable), supprimée à la désinstallation.
 */
final class PolicyTemplateTables implements FeatureTables
{
    public const IMPORTS = 'glpi_plugin_grcmanager_policytemplateimports';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::IMPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::IMPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `template_key` varchar(64) NOT NULL DEFAULT '',
                `language` varchar(8) NOT NULL DEFAULT '',
                `plugin_grcmanager_policies_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Personne qui a importe le modele',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `template_key` (`template_key`),
                KEY `plugin_grcmanager_policies_id` (`plugin_grcmanager_policies_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::IMPORTS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

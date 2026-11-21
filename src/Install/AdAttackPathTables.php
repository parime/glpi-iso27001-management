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
 * Issue #835, lot 3 : imports des chemins d'attaque de l'Active Directory (BloodHound CE,
 * Adalanche), un par fichier, et comptes les plus exposés de chaque import (nom, type, nombre de
 * sauts vers Domain Admins). Aucune autre donnée du graphe n'est gardée. Le risque du registre créé
 * sur clic est noté sur l'import (plugin_grcmanager_risks_id).
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class AdAttackPathTables implements FeatureTables
{
    public const IMPORTS = 'glpi_plugin_grcmanager_adattackpaths';

    public const ACCOUNTS = 'glpi_plugin_grcmanager_adattackpathaccounts';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::IMPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::IMPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `tool` varchar(32) NOT NULL DEFAULT 'bloodhound',
                `domain` varchar(255) NOT NULL DEFAULT '',
                `path_count` int NOT NULL DEFAULT 0 COMMENT 'Comptes ayant un chemin vers Domain Admins',
                `user_count` int NOT NULL DEFAULT 0,
                `computer_count` int NOT NULL DEFAULT 0,
                `shortest_path` int DEFAULT NULL COMMENT 'Sauts du plus court chemin',
                `node_count` int NOT NULL DEFAULT 0,
                `edge_count` int NOT NULL DEFAULT 0,
                `truncated` tinyint NOT NULL DEFAULT 0,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL DEFAULT 0,
                `file_name` varchar(255) NOT NULL DEFAULT '',
                `file_sha256` char(64) NOT NULL DEFAULT '',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `domain` (`domain`),
                KEY `file_sha256` (`file_sha256`),
                KEY `plugin_grcmanager_risks_id` (`plugin_grcmanager_risks_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::ACCOUNTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ACCOUNTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_adattackpaths_id` int {$keySign} NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `kind` varchar(16) NOT NULL DEFAULT '',
                `hops` int NOT NULL DEFAULT 0,
                `routes` int NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_adattackpaths_id` (`plugin_grcmanager_adattackpaths_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::ACCOUNTS);
        $migration->dropTable(self::IMPORTS);
    }

    /**
     * @return list<class-string>
     */
    public static function pluginClasses(): array
    {
        return [];
    }
}

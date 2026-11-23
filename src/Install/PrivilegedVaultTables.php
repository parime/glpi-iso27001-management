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
 * Issue #859 : coffre-fort de mots de passe et comptes à privilèges (A.5.17, A.8.2). Seulement des
 * métadonnées, jamais un secret : éléments du coffre (nom, collection, identifiant de connexion,
 * date du dernier changement, personnes qui y ont accès) et membres du coffre (adresse, rôle,
 * statut, collections, double authentification). Un import remplace les lignes de la même source
 * dans la même entité (photographie). Créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class PrivilegedVaultTables implements FeatureTables
{
    public const ENTRIES = 'glpi_plugin_grcmanager_vaultentries';
    public const MEMBERS = 'glpi_plugin_grcmanager_vaultmembers';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::ENTRIES)) {
            $DB->doQuery("CREATE TABLE `" . self::ENTRIES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `source` varchar(16) NOT NULL DEFAULT 'bitwarden',
                `external_id` varchar(255) NOT NULL DEFAULT '' COMMENT 'Identifiant dans le coffre',
                `name` varchar(255) NOT NULL DEFAULT '',
                `collection` varchar(255) NOT NULL DEFAULT '' COMMENT 'Collections ou coffres, separes par des virgules',
                `username` varchar(255) NOT NULL DEFAULT '' COMMENT 'Identifiant de connexion, jamais le mot de passe',
                `uri` varchar(255) NOT NULL DEFAULT '',
                `date_changed` timestamp NULL DEFAULT NULL COMMENT 'Dernier changement du secret',
                `holders` text COMMENT 'Adresses des personnes qui y ont acces, separees par des virgules',
                `users_id_import` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `source` (`source`),
                KEY `users_id_import` (`users_id_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
        if (!$DB->tableExists(self::MEMBERS)) {
            $DB->doQuery("CREATE TABLE `" . self::MEMBERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `source` varchar(16) NOT NULL DEFAULT 'bitwarden',
                `external_id` varchar(255) NOT NULL DEFAULT '',
                `email` varchar(255) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `role` varchar(16) NOT NULL DEFAULT 'user' COMMENT 'owner, admin, user, custom',
                `status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'active, invited, revoked',
                `collections` text COMMENT 'Collections accessibles, separees par des virgules',
                `two_factor` tinyint NOT NULL DEFAULT -1 COMMENT '-1 inconnu, 0 non, 1 oui',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Compte GLPI rapproche, 0 sinon',
                `users_id_import` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `source` (`source`),
                KEY `users_id` (`users_id`),
                KEY `users_id_import` (`users_id_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::ENTRIES);
        $migration->dropTable(self::MEMBERS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

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
 * Issue #846 : droits d'accès aux partages de fichiers Windows (SMB et NTFS).
 *
 * - SHARES : partages importés (serveur, nom, chemin), avec ce que l'organisation y ajoute et qui
 *   survit aux imports suivants : classification C/I/D, propriétaire désigné, état de la revue.
 * - ACES : droits importés (droit de partage ou droit NTFS d'un dossier), compte rapproché d'un
 *   utilisateur ou d'un groupe GLPI, décision de revue. Un droit absent du nouvel import passe à
 *   l'état « retiré » (suivi d'un import à l'autre) ; il est effacé à l'import suivant.
 * - IMPORTS : historique des imports par serveur (droits ajoutés et retirés).
 *
 * Aucun contenu de fichier n'est stocké, seulement des noms de dossiers et de comptes. Tables
 * créées si absentes (rejouable), supprimées à la désinstallation ; classe découverte par
 * l'installeur (FeatureTablesRegistry).
 */
final class ShareAccessTables implements FeatureTables
{
    public const SHARES = 'glpi_plugin_grcmanager_fileshares';

    public const ACES = 'glpi_plugin_grcmanager_fileshareaces';

    public const IMPORTS = 'glpi_plugin_grcmanager_fileshareimports';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::SHARES)) {
            $DB->doQuery("CREATE TABLE `" . self::SHARES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors du premier import',
                `source` varchar(32) NOT NULL DEFAULT 'smb' COMMENT 'smb, puis sharepoint, onedrive, teams',
                `server` varchar(255) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom du partage',
                `share_path` text COMMENT 'Chemin local sur le serveur',
                `owner` varchar(255) NOT NULL DEFAULT '' COMMENT 'Proprietaire NTFS de la racine',
                `users_id_owner` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Proprietaire designe (GLPI)',
                `confidentiality` varchar(16) NOT NULL DEFAULT '',
                `integrity` varchar(16) NOT NULL DEFAULT '',
                `availability` varchar(16) NOT NULL DEFAULT '',
                `comment` text,
                `is_present` tinyint NOT NULL DEFAULT 1 COMMENT '0 si absent du dernier import du serveur',
                `review_status` varchar(16) NOT NULL DEFAULT '' COMMENT 'open pendant une revue',
                `date_review_start` timestamp NULL DEFAULT NULL,
                `date_last_review` timestamp NULL DEFAULT NULL,
                `users_id_last_review` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `source`, `server`(150), `name`(150)),
                KEY `users_id_owner` (`users_id_owner`),
                KEY `users_id_last_review` (`users_id_last_review`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::ACES)) {
            $DB->doQuery("CREATE TABLE `" . self::ACES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_fileshares_id` int {$keySign} NOT NULL DEFAULT 0,
                `fingerprint` char(40) NOT NULL DEFAULT '',
                `scope` varchar(16) NOT NULL DEFAULT 'ntfs' COMMENT 'share ou ntfs',
                `folder` text,
                `depth` int NOT NULL DEFAULT 0,
                `identity` varchar(255) NOT NULL DEFAULT '',
                `sid` varchar(190) NOT NULL DEFAULT '',
                `kind` varchar(16) NOT NULL DEFAULT 'unknown' COMMENT 'broad, system, orphan, user, group, unknown',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Utilisateur GLPI rapproche, 0 sinon',
                `groups_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Groupe GLPI rapproche, 0 sinon',
                `rights` varchar(255) NOT NULL DEFAULT '' COMMENT 'Droit tel qu exporte',
                `level` varchar(16) NOT NULL DEFAULT 'other' COMMENT 'read, modify, full, other',
                `is_deny` tinyint NOT NULL DEFAULT 0,
                `is_inherited` tinyint NOT NULL DEFAULT 0,
                `is_protected` tinyint NOT NULL DEFAULT 0 COMMENT 'Heritage desactive sur le dossier',
                `folder_owner` varchar(255) NOT NULL DEFAULT '',
                `status` varchar(16) NOT NULL DEFAULT 'current' COMMENT 'current ou removed',
                `date_first_seen` timestamp NULL DEFAULT NULL,
                `date_removed` timestamp NULL DEFAULT NULL,
                `decision` varchar(16) NOT NULL DEFAULT '' COMMENT 'pending, keep, modify, remove pendant une revue',
                `decision_comment` text,
                `users_id_decision` int {$keySign} NOT NULL DEFAULT 0,
                `date_decision` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_fileshares_id` (`plugin_grcmanager_fileshares_id`),
                KEY `fingerprint` (`fingerprint`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `groups_id` (`groups_id`),
                KEY `users_id_decision` (`users_id_decision`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::IMPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::IMPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `source` varchar(32) NOT NULL DEFAULT 'smb',
                `server` varchar(255) NOT NULL DEFAULT '',
                `shares` int NOT NULL DEFAULT 0,
                `aces` int NOT NULL DEFAULT 0,
                `added` int NOT NULL DEFAULT 0,
                `removed` int NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `users_id` (`users_id`),
                KEY `date_import` (`date_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::SHARES);
        $migration->dropTable(self::ACES);
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

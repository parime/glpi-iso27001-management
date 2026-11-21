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
 * Issue #852 : méthodes d'authentification importées par compte (export CSV d'Entra ID, Okta,
 * Google Workspace ou d'une solution MFA sur site). Un import remplace les lignes du même fournisseur
 * dans la même entité (photographie). Aucun secret n'est stocké : seulement le nom des méthodes.
 * Créée si absente (rejouable), supprimée à la désinstallation.
 */
final class MfaCoverageTables implements FeatureTables
{
    public const ACCOUNTS = 'glpi_plugin_grcmanager_mfaaccounts';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::ACCOUNTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ACCOUNTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `provider` varchar(32) NOT NULL DEFAULT 'other',
                `account` varchar(255) NOT NULL DEFAULT '' COMMENT 'Identifiant chez le fournisseur',
                `email` varchar(255) NOT NULL DEFAULT '',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Compte GLPI rapproche, 0 sinon',
                `methods` text COMMENT 'Noms des methodes, separes par des virgules',
                `is_mfa_registered` tinyint NOT NULL DEFAULT 0,
                `is_admin` tinyint NOT NULL DEFAULT 0 COMMENT 'Role d administrateur chez le fournisseur',
                `is_remote` tinyint NOT NULL DEFAULT 0 COMMENT 'Acces distant declare',
                `is_excluded` tinyint NOT NULL DEFAULT 0 COMMENT 'Compte exclu de la MFA (service)',
                `exclusion_reason` text,
                `users_id_import` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `provider` (`provider`),
                KEY `users_id` (`users_id`),
                KEY `users_id_import` (`users_id_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::ACCOUNTS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

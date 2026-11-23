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
 * Issue #1056 : catalogue des produits d'endoflife.date (une ligne par produit, termes de
 * rapprochement en JSON) et refus des correspondances proposées. Créées si absentes (rejouable),
 * supprimées à la désinstallation.
 */
final class EndOfLifeCatalogTables implements FeatureTables
{
    public const CATALOG = 'glpi_plugin_grcmanager_endoflifecatalogs';
    public const REJECTS = 'glpi_plugin_grcmanager_endoflifesuggestrejects';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::CATALOG)) {
            $DB->doQuery("CREATE TABLE `" . self::CATALOG . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `product` varchar(64) NOT NULL DEFAULT '' COMMENT 'Identifiant endoflife.date',
                `label` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom affiche du produit',
                `category` varchar(32) NOT NULL DEFAULT '' COMMENT 'Categorie (os, app...)',
                `terms` mediumtext COMMENT 'Termes de rapprochement (JSON)',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `product` (`product`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
        if (!$DB->tableExists(self::REJECTS)) {
            $DB->doQuery("CREATE TABLE `" . self::REJECTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `kind` varchar(16) NOT NULL DEFAULT '' COMMENT 'software, os ou firmware',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom dans GLPI',
                `product` varchar(64) NOT NULL DEFAULT '' COMMENT 'Produit propose et refuse',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur du refus',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `kind_name` (`kind`, `name`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::CATALOG);
        $migration->dropTable(self::REJECTS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

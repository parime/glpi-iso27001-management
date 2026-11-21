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
 * Issue #838 : couverture des sauvegardes.
 *
 * - HOSTS : machines sauvegardées, lues par le connecteur Veeam ou importées par CSV (Acronis,
 *   Commvault, Synology, Proxmox Backup Server…) : nom, adresse, travail, dernière sauvegarde
 *   réussie, dernière tentative et son résultat. Une lecture ou un import remplace les lignes du
 *   même outil dans la même entité (photographie). Aucun contenu sauvegardé n'est lu. Le
 *   rapprochement avec les actifs GLPI se fait à la lecture.
 * - JOBS : état des travaux de sauvegarde lus par le connecteur Veeam (dernier résultat).
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class BackupCoverageTables
{
    public const HOSTS = 'glpi_plugin_grcmanager_backuphosts';

    public const JOBS = 'glpi_plugin_grcmanager_backupjobs';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::HOSTS)) {
            $DB->doQuery("CREATE TABLE `" . self::HOSTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `source` varchar(32) NOT NULL DEFAULT 'other' COMMENT 'Outil de sauvegarde',
                `origin` varchar(16) NOT NULL DEFAULT 'csv' COMMENT 'csv ou api (connecteur)',
                `host` varchar(255) NOT NULL DEFAULT '' COMMENT 'Machine sauvegardee',
                `ip` varchar(64) NOT NULL DEFAULT '',
                `job` varchar(255) NOT NULL DEFAULT '' COMMENT 'Travail de sauvegarde',
                `last_success` timestamp NULL DEFAULT NULL COMMENT 'Derniere sauvegarde reussie',
                `last_attempt` timestamp NULL DEFAULT NULL COMMENT 'Derniere tentative',
                `last_result` varchar(16) NOT NULL DEFAULT 'unknown',
                `users_id_import` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `source` (`source`),
                KEY `host` (`host`),
                KEY `users_id_import` (`users_id_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::JOBS)) {
            $DB->doQuery("CREATE TABLE `" . self::JOBS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `source` varchar(32) NOT NULL DEFAULT 'veeam',
                `name` varchar(255) NOT NULL DEFAULT '',
                `type` varchar(64) NOT NULL DEFAULT '',
                `last_run` timestamp NULL DEFAULT NULL,
                `last_result` varchar(16) NOT NULL DEFAULT 'unknown',
                `objects` int NOT NULL DEFAULT 0 COMMENT 'Objets proteges par le travail',
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `source` (`source`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::HOSTS);
        $migration->dropTable(self::JOBS);
    }
}

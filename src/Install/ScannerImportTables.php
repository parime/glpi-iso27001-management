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
 * Issue #543 : imports des rapports de scanners de vulnérabilités (Greenbone / OpenVAS d'abord) et
 * constats, un par outil, hôte, port et test (finding_key) dans l'entité de l'import : rejouer un
 * rapport, ou importer l'analyse suivante, met à jour le constat au lieu d'en créer un autre. Un
 * même rapport (report_uuid) n'a qu'une ligne d'import par entité. Le fichier n'est pas conservé.
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation. Aucune autre table n'est
 * modifiée.
 */
final class ScannerImportTables implements FeatureTables
{
    public const IMPORTS = 'glpi_plugin_grcmanager_scannerimports';

    public const FINDINGS = 'glpi_plugin_grcmanager_scannerfindings';

    public const FK = 'plugin_grcmanager_scannerimports_id';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::IMPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::IMPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `scanner` varchar(32) NOT NULL DEFAULT '',
                `report_uuid` varchar(64) NOT NULL DEFAULT '' COMMENT 'Identifiant du rapport chez l outil',
                `task_name` varchar(255) NOT NULL DEFAULT '',
                `scan_start` timestamp NULL DEFAULT NULL,
                `scan_end` timestamp NULL DEFAULT NULL,
                `host_count` int NOT NULL DEFAULT 0,
                `unmatched_count` int NOT NULL DEFAULT 0 COMMENT 'Hotes sans ordinateur GLPI',
                `finding_count` int NOT NULL DEFAULT 0,
                `critical_count` int NOT NULL DEFAULT 0,
                `high_count` int NOT NULL DEFAULT 0,
                `medium_count` int NOT NULL DEFAULT 0,
                `low_count` int NOT NULL DEFAULT 0,
                `resolved_count` int NOT NULL DEFAULT 0 COMMENT 'Constats absents de cette analyse',
                `file_name` varchar(255) NOT NULL DEFAULT '',
                `file_sha256` char(64) NOT NULL DEFAULT '',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `report` (`entities_id`, `scanner`, `report_uuid`),
                KEY `scan_start` (`scan_start`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::FINDINGS)) {
            $DB->doQuery("CREATE TABLE `" . self::FINDINGS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `scanner` varchar(32) NOT NULL DEFAULT '',
                `finding_key` char(64) NOT NULL DEFAULT '' COMMENT 'Outil, hote, port et test',
                `host` varchar(255) NOT NULL DEFAULT '' COMMENT 'Adresse IP ou nom analyse',
                `hostname` varchar(255) NOT NULL DEFAULT '',
                `port` varchar(64) NOT NULL DEFAULT '',
                `test_id` varchar(128) NOT NULL DEFAULT '' COMMENT 'Identifiant du test (OID NVT)',
                `name` varchar(255) NOT NULL DEFAULT '',
                `family` varchar(128) NOT NULL DEFAULT '',
                `severity` decimal(3,1) NOT NULL DEFAULT 0.0 COMMENT 'Score CVSS 0 a 10',
                `qod` int DEFAULT NULL COMMENT 'Qualite de detection (%)',
                `cves` text,
                `solution_type` varchar(32) NOT NULL DEFAULT '',
                `solution` text,
                `computers_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_resolved` tinyint NOT NULL DEFAULT 0 COMMENT 'Absent d une analyse plus recente de l hote',
                `first_seen` timestamp NULL DEFAULT NULL,
                `last_seen` timestamp NULL DEFAULT NULL,
                `date_resolved` timestamp NULL DEFAULT NULL,
                `" . self::FK . "` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Dernier import qui l a vu',
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `finding` (`entities_id`, `finding_key`),
                KEY `host` (`host`),
                KEY `computers_id` (`computers_id`),
                KEY `is_resolved` (`is_resolved`),
                KEY `" . self::FK . "` (`" . self::FK . "`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::FINDINGS);
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

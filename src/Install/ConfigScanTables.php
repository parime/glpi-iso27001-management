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
 * Issue #781 : rapports de conformité de configuration importés (résultats XCCDF/ARF d'OpenSCAP) et
 * règles en échec de chaque rapport. Seuls des compteurs et les règles en échec ou en erreur sont
 * gardés, jamais le fichier. Créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class ConfigScanTables implements FeatureTables
{
    public const REPORTS = 'glpi_plugin_grcmanager_configscanreports';

    public const FAILURES = 'glpi_plugin_grcmanager_configscanfailures';

    public const FK = 'plugin_grcmanager_configscanreports_id';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::REPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::REPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `computers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT '0 : rapport non rapproche',
                `hostname` varchar(255) NOT NULL DEFAULT '',
                `fqdn` varchar(255) NOT NULL DEFAULT '',
                `address` varchar(255) NOT NULL DEFAULT '',
                `benchmark_id` varchar(255) NOT NULL DEFAULT '',
                `benchmark_title` varchar(255) NOT NULL DEFAULT '',
                `profile_id` varchar(255) NOT NULL DEFAULT '',
                `profile_title` varchar(255) NOT NULL DEFAULT '',
                `scanner` varchar(255) NOT NULL DEFAULT '',
                `scan_date` timestamp NULL DEFAULT NULL,
                `pass_count` int NOT NULL DEFAULT 0,
                `fail_count` int NOT NULL DEFAULT 0,
                `error_count` int NOT NULL DEFAULT 0,
                `fixed_count` int NOT NULL DEFAULT 0,
                `other_count` int NOT NULL DEFAULT 0 COMMENT 'Non applicables, non verifiees, autres',
                `high_fail_count` int NOT NULL DEFAULT 0,
                `score` decimal(8,2) DEFAULT NULL,
                `score_max` decimal(8,2) DEFAULT NULL,
                `file_name` varchar(255) NOT NULL DEFAULT '',
                `is_truncated` tinyint NOT NULL DEFAULT 0,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Ticket propose, cree a la demande',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de l import',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `computers_id` (`computers_id`),
                KEY `scan_date` (`scan_date`),
                KEY `hostname` (`hostname`),
                KEY `tickets_id` (`tickets_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
        if (!$DB->tableExists(self::FAILURES)) {
            $DB->doQuery("CREATE TABLE `" . self::FAILURES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `" . self::FK . "` int {$keySign} NOT NULL DEFAULT 0,
                `rule_id` varchar(255) NOT NULL DEFAULT '',
                `title` varchar(255) NOT NULL DEFAULT '',
                `severity` varchar(20) NOT NULL DEFAULT 'unknown',
                `result` varchar(20) NOT NULL DEFAULT 'fail',
                PRIMARY KEY (`id`),
                KEY `" . self::FK . "` (`" . self::FK . "`),
                KEY `rule_id` (`rule_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::FAILURES);
        $migration->dropTable(self::REPORTS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

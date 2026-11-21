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
 * Issue #835 : imports des rapports d'audit de sécurité de l'Active Directory (PingCastle d'abord),
 * un par fichier, et règles déclenchées de chaque import. Seuls les scores, l'identifiant, la
 * catégorie, les points et le constat de chaque règle sont gardés : jamais le détail des comptes ni
 * un mot de passe du rapport. Le rapport brut, nettoyé de ses secrets, peut être archivé comme
 * document restreint (documents_id).
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation. Aucune autre table n'est
 * modifiée.
 */
final class AdSecurityAuditTables
{
    public const AUDITS = 'glpi_plugin_grcmanager_adaudits';

    public const RULES = 'glpi_plugin_grcmanager_adauditrules';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::AUDITS)) {
            $DB->doQuery("CREATE TABLE `" . self::AUDITS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `tool` varchar(32) NOT NULL DEFAULT 'pingcastle',
                `domain` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom DNS du domaine audite',
                `netbios` varchar(64) NOT NULL DEFAULT '',
                `report_date` timestamp NULL DEFAULT NULL COMMENT 'Date de generation du rapport',
                `engine_version` varchar(64) NOT NULL DEFAULT '',
                `global_score` int NOT NULL DEFAULT 0,
                `stale_score` int DEFAULT NULL,
                `privileged_score` int DEFAULT NULL,
                `trust_score` int DEFAULT NULL,
                `anomaly_score` int DEFAULT NULL,
                `maturity_level` int DEFAULT NULL,
                `rule_count` int NOT NULL DEFAULT 0,
                `file_name` varchar(255) NOT NULL DEFAULT '',
                `file_sha256` char(64) NOT NULL DEFAULT '',
                `secrets_found` tinyint NOT NULL DEFAULT 0 COMMENT 'Secrets presents dans le rapport, jamais stockes',
                `documents_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Rapport nettoye, document restreint',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `domain` (`domain`),
                KEY `file_sha256` (`file_sha256`),
                KEY `documents_id` (`documents_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::RULES)) {
            $DB->doQuery("CREATE TABLE `" . self::RULES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_adaudits_id` int {$keySign} NOT NULL DEFAULT 0,
                `rule_id` varchar(128) NOT NULL DEFAULT '' COMMENT 'Identifiant de la regle chez l outil',
                `category` varchar(32) NOT NULL DEFAULT '',
                `model` varchar(128) NOT NULL DEFAULT '',
                `points` int NOT NULL DEFAULT 0,
                `rationale` text COMMENT 'Constat de l outil, sans le detail des comptes',
                `plugin_grcmanager_nonconformities_id` int {$keySign} NOT NULL DEFAULT 0,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_adaudits_id` (`plugin_grcmanager_adaudits_id`),
                KEY `rule_id` (`rule_id`),
                KEY `plugin_grcmanager_nonconformities_id` (`plugin_grcmanager_nonconformities_id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::RULES);
        $migration->dropTable(self::AUDITS);
    }
}

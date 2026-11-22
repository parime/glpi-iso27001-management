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

use CronTask;
use GlpiPlugin\Grcmanager\CodeSecurity;
use Migration;

/**
 * Issue #858 : alertes de sécurité du code lues dans GitHub et GitLab (lecture seule).
 * - REPOSITORIES : dernière lecture du dépôt de chaque application du registre (#777) : fournisseur,
 *   analyses activées ou non, erreur éventuelle ;
 * - ALERTS : alertes ouvertes du dépôt (remplacées à chaque lecture réussie du dépôt). Aucune valeur
 *   de secret ni aucun jeton n'y est stocké.
 * L'entité est celle de l'application (jointure sur glpi_appliances). Créées si absentes (rejouable),
 * supprimées à la désinstallation. La tâche automatique est enregistrée désactivée.
 */
final class CodeSecurityTables implements FeatureTables
{
    public const REPOSITORIES = 'glpi_plugin_grcmanager_coderepositories';
    public const ALERTS = 'glpi_plugin_grcmanager_codesecurityalerts';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::REPOSITORIES)) {
            $DB->doQuery("CREATE TABLE `" . self::REPOSITORIES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `provider` varchar(16) NOT NULL DEFAULT '' COMMENT 'github, gitlab ; vide : non reconnu',
                `repository` varchar(255) NOT NULL DEFAULT '' COMMENT 'proprietaire/depot ou groupe/projet',
                `status` varchar(16) NOT NULL DEFAULT '' COMMENT 'ok, unsupported, not_configured, error',
                `error` varchar(32) NOT NULL DEFAULT '' COMMENT 'Code d erreur de la derniere lecture',
                `dependency_scan` varchar(16) NOT NULL DEFAULT '' COMMENT 'ok, unavailable ; vide : non lu',
                `secret_scan` varchar(16) NOT NULL DEFAULT '',
                `code_scan` varchar(16) NOT NULL DEFAULT '',
                `truncated` tinyint NOT NULL DEFAULT 0 COMMENT 'Premieres alertes seulement',
                `date_read` timestamp NULL DEFAULT NULL COMMENT 'Derniere lecture reussie',
                `date_attempt` timestamp NULL DEFAULT NULL COMMENT 'Derniere tentative',
                PRIMARY KEY (`id`),
                UNIQUE KEY `appliances_id` (`appliances_id`),
                KEY `provider` (`provider`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::ALERTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ALERTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `provider` varchar(16) NOT NULL DEFAULT '',
                `category` varchar(16) NOT NULL DEFAULT '' COMMENT 'dependency, secret, code',
                `alert_key` varchar(32) NOT NULL DEFAULT '' COMMENT 'Numero de l alerte chez le fournisseur',
                `severity` varchar(16) NOT NULL DEFAULT '' COMMENT 'critical, high, medium, low',
                `title` varchar(255) NOT NULL DEFAULT '',
                `detail` varchar(255) NOT NULL DEFAULT '' COMMENT 'Paquet, outil et fichier, type de secret',
                `reference` varchar(64) NOT NULL DEFAULT '' COMMENT 'CVE ou GHSA',
                `validity` varchar(16) NOT NULL DEFAULT '' COMMENT 'Secret : active, inactive, unknown',
                `url` text COMMENT 'Page de l alerte chez le fournisseur',
                `date_detected` timestamp NULL DEFAULT NULL,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `appliances_id` (`appliances_id`),
                KEY `category` (`category`),
                KEY `severity` (`severity`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        CronTask::Register(
            CodeSecurity::class,
            CodeSecurity::CRON_NAME,
            DAY_TIMESTAMP,
            [
                'comment' => 'Lit les alertes de securite du code de GitHub et GitLab (lecture seule)',
                'mode'    => CronTask::MODE_EXTERNAL,
                'state'   => CronTask::STATE_DISABLE,
            ]
        );
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::REPOSITORIES);
        $migration->dropTable(self::ALERTS);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

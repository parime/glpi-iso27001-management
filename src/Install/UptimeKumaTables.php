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
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use Migration;

/**
 * Issue #842 : lectures d'Uptime Kuma (lecture seule).
 * - MONITORS : dernière photographie des sondes (remplacée à chaque lecture complète, dans l'entité
 *   des lectures) ;
 * - SAMPLES : relevés d'état successifs de chaque sonde (un à chaque lecture de « /metrics », et ceux
 *   des pages de statut), gardés 90 jours, pour la disponibilité et la plus longue panne de la période ;
 * - LINKS : choix faits dans GLPI pour une sonde (processus métier, incident proposé), gardés d'une
 *   lecture à l'autre.
 * Aucune clé d'API n'y est stockée. Créées si absentes (rejouable), supprimées à la désinstallation.
 * La tâche automatique de lecture est enregistrée désactivée (un réglage existant est gardé).
 */
final class UptimeKumaTables implements FeatureTables
{
    public const MONITORS = 'glpi_plugin_grcmanager_uptimekumamonitors';
    public const SAMPLES = 'glpi_plugin_grcmanager_uptimekumasamples';
    public const LINKS = 'glpi_plugin_grcmanager_uptimekumalinks';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::MONITORS)) {
            $DB->doQuery("CREATE TABLE `" . self::MONITORS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite des lectures',
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `monitor_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'Identifiant ou nom de la sonde',
                `monitor_id` varchar(32) NOT NULL DEFAULT '' COMMENT 'Identifiant Uptime Kuma',
                `name` varchar(255) NOT NULL DEFAULT '',
                `type` varchar(32) NOT NULL DEFAULT '' COMMENT 'http, keyword, port, ping, dns...',
                `url` text COMMENT 'Adresse surveillee, sans parametres',
                `hostname` varchar(255) NOT NULL DEFAULT '',
                `port` varchar(8) NOT NULL DEFAULT '',
                `status` tinyint NOT NULL DEFAULT -1 COMMENT '0 en panne, 1 en service, 2 en attente, 3 maintenance',
                `response_time` int DEFAULT NULL COMMENT 'Millisecondes',
                `cert_days` int DEFAULT NULL COMMENT 'Jours avant expiration du certificat',
                `cert_valid` tinyint DEFAULT NULL,
                `uptime_24h` decimal(6,2) DEFAULT NULL COMMENT 'Disponibilite 24 h d Uptime Kuma, %',
                `status_pages` varchar(255) NOT NULL DEFAULT '' COMMENT 'Pages de statut de la sonde',
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `monitor_key` (`monitor_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::SAMPLES)) {
            $DB->doQuery("CREATE TABLE `" . self::SAMPLES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `monitor_key` varchar(64) NOT NULL DEFAULT '',
                `date_sample` timestamp NULL DEFAULT NULL,
                `status` tinyint NOT NULL DEFAULT -1,
                `response_time` int DEFAULT NULL COMMENT 'Millisecondes',
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `monitor_date` (`monitor_key`, `date_sample`),
                KEY `date_sample` (`date_sample`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::LINKS)) {
            $DB->doQuery("CREATE TABLE `" . self::LINKS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `monitor_key` varchar(64) NOT NULL DEFAULT '',
                `plugin_grcmanager_businessprocesses_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Processus metier choisi dans GLPI',
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Incident cree sur clic',
                `date_outage` timestamp NULL DEFAULT NULL COMMENT 'Debut de la panne de l incident',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `monitor_key`),
                KEY `plugin_grcmanager_businessprocesses_id` (`plugin_grcmanager_businessprocesses_id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        CronTask::Register(
            UptimeKumaMonitoring::class,
            UptimeKumaMonitoring::CRON_NAME,
            HOUR_TIMESTAMP,
            [
                'comment' => 'Lit les sondes et certificats d Uptime Kuma (lecture seule)',
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
        $migration->dropTable(self::MONITORS);
        $migration->dropTable(self::SAMPLES);
        $migration->dropTable(self::LINKS);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

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
 * Issue #853 : couverture de la journalisation.
 *
 * - HOSTS : hôtes émetteurs importés d'une plateforme de journaux (export CSV de Graylog,
 *   Elastic/OpenSearch, Splunk, Wazuh ou d'un serveur syslog) : nom, adresse, dernier événement
 *   reçu. Un import remplace les lignes de la même plateforme dans la même entité (photographie).
 *   Aucun contenu de journal n'est stocké. Le rapprochement avec les actifs GLPI se fait à la lecture.
 * - PLATFORMS : déclaration, par plateforme et par entité, de la durée de conservation, de
 *   l'obligation qui l'impose et de la synchronisation des horloges (NTP).
 *
 * Tables créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class LogCoverageTables
{
    public const HOSTS = 'glpi_plugin_grcmanager_loghosts';

    public const PLATFORMS = 'glpi_plugin_grcmanager_logplatforms';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::HOSTS)) {
            $DB->doQuery("CREATE TABLE `" . self::HOSTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active lors de l import',
                `platform` varchar(32) NOT NULL DEFAULT 'other',
                `host` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom de l hote emetteur',
                `ip` varchar(64) NOT NULL DEFAULT '',
                `last_event` timestamp NULL DEFAULT NULL COMMENT 'Dernier evenement recu, NULL si inconnu',
                `users_id_import` int {$keySign} NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `platform` (`platform`),
                KEY `host` (`host`),
                KEY `users_id_import` (`users_id_import`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::PLATFORMS)) {
            $DB->doQuery("CREATE TABLE `" . self::PLATFORMS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `platform` varchar(32) NOT NULL DEFAULT 'other',
                `retention_days` int NOT NULL DEFAULT 0 COMMENT 'Conservation declaree en jours, 0 = non declaree',
                `plugin_grcmanager_complianceobligations_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Obligation qui impose une conservation, 0 = aucune',
                `obligation_days` int NOT NULL DEFAULT 0 COMMENT 'Conservation exigee par l obligation, en jours',
                `is_ntp_declared` tinyint NOT NULL DEFAULT 0 COMMENT 'Horloges synchronisees (declare)',
                `ntp_server` varchar(255) NOT NULL DEFAULT '',
                `comment` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la declaration',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `platform`),
                KEY `plugin_grcmanager_complianceobligations_id` (`plugin_grcmanager_complianceobligations_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::HOSTS);
        $migration->dropTable(self::PLATFORMS);
    }
}

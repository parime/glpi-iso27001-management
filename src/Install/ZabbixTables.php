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
 * Issue #836 : dernière lecture de Zabbix (photographie, lecture seule) : hôtes, problèmes de la
 * période et disponibilité des services (SLA). Une lecture complète remplace la précédente dans
 * l'entité des lectures ; les liens créés dans GLPI (incident ou ticket proposé depuis un problème,
 * processus métier d'un service) sont gardés d'une lecture à l'autre. Aucun jeton n'y est stocké.
 * Créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class ZabbixTables implements FeatureTables
{
    public const HOSTS = 'glpi_plugin_grcmanager_zabbixhosts';
    public const PROBLEMS = 'glpi_plugin_grcmanager_zabbixproblems';
    public const SERVICES = 'glpi_plugin_grcmanager_zabbixservices';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::HOSTS)) {
            $DB->doQuery("CREATE TABLE `" . self::HOSTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite des lectures',
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `hostid` varchar(32) NOT NULL DEFAULT '' COMMENT 'Identifiant Zabbix',
                `host` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom technique',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom visible',
                `ips` text COMMENT 'Adresses IP des interfaces, separees par des virgules',
                `dns` text COMMENT 'Noms DNS des interfaces, separes par des virgules',
                `serial` varchar(255) NOT NULL DEFAULT '' COMMENT 'Numero de serie de l inventaire Zabbix',
                `is_monitored` tinyint NOT NULL DEFAULT 0,
                `availability` varchar(64) NOT NULL DEFAULT '' COMMENT 'Etat des interfaces (0, 1, 2)',
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `hostid` (`hostid`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::PROBLEMS)) {
            $DB->doQuery("CREATE TABLE `" . self::PROBLEMS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `eventid` varchar(32) NOT NULL DEFAULT '' COMMENT 'Evenement Zabbix du probleme',
                `hostid` varchar(32) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `severity` tinyint NOT NULL DEFAULT 0 COMMENT '0 a 5, gravite Zabbix',
                `date_problem` timestamp NULL DEFAULT NULL,
                `date_recovery` timestamp NULL DEFAULT NULL,
                `is_open` tinyint NOT NULL DEFAULT 0,
                `is_acknowledged` tinyint NOT NULL DEFAULT 0,
                `capacity` varchar(16) NOT NULL DEFAULT '' COMMENT 'storage, memory, cpu ou vide',
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Incident cree sur clic',
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Ticket cree sur clic',
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `eventid` (`eventid`),
                KEY `hostid` (`hostid`),
                KEY `severity` (`severity`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::SERVICES)) {
            $DB->doQuery("CREATE TABLE `" . self::SERVICES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `slaid` varchar(32) NOT NULL DEFAULT '',
                `sla_name` varchar(255) NOT NULL DEFAULT '',
                `slo` decimal(8,4) DEFAULT NULL COMMENT 'Objectif en %',
                `serviceid` varchar(32) NOT NULL DEFAULT '',
                `service_name` varchar(255) NOT NULL DEFAULT '',
                `sli` decimal(8,4) DEFAULT NULL COMMENT 'Disponibilite de la periode en cours, %',
                `sli_previous` decimal(8,4) DEFAULT NULL COMMENT 'Disponibilite de la periode precedente, %',
                `downtime` int NOT NULL DEFAULT 0 COMMENT 'Secondes d arret de la periode en cours',
                `period_from` timestamp NULL DEFAULT NULL,
                `period_to` timestamp NULL DEFAULT NULL,
                `plugin_grcmanager_businessprocesses_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Processus metier choisi dans GLPI',
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `serviceid` (`serviceid`),
                KEY `plugin_grcmanager_businessprocesses_id` (`plugin_grcmanager_businessprocesses_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::HOSTS);
        $migration->dropTable(self::PROBLEMS);
        $migration->dropTable(self::SERVICES);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

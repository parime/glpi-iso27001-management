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
use GlpiPlugin\Grcmanager\ServerManagement;
use Migration;

/**
 * Issue #843, lot 3 : santé détaillée des serveurs lue sur leur contrôleur de gestion (Redfish, lecture
 * seule) : composants (alimentations, disques, ventilateurs, températures) et événements récents du
 * journal du contrôleur. Remplacés à chaque lecture réussie de la santé, sinon gardés. Lecture planifiée
 * de tous les contrôleurs Redfish par une tâche automatique DÉSACTIVÉE à l'installation (un réglage
 * existant est gardé). Tables supprimées à la désinstallation (la tâche part avec celles du plugin).
 */
final class ServerHealthTables implements FeatureTables
{
    public const COMPONENTS = 'glpi_plugin_grcmanager_bmchealthcomponents';
    public const EVENTS = 'glpi_plugin_grcmanager_bmcevents';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::COMPONENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::COMPONENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_bmccontrollers_id` int {$keySign} NOT NULL DEFAULT 0,
                `kind` varchar(16) NOT NULL DEFAULT '' COMMENT 'power, drive, fan ou temperature',
                `name` varchar(255) NOT NULL DEFAULT '',
                `health` varchar(16) NOT NULL DEFAULT '' COMMENT 'OK, Warning, Critical ou vide',
                `reading` varchar(255) NOT NULL DEFAULT '',
                `date_read` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_bmccontrollers_id` (`plugin_grcmanager_bmccontrollers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::EVENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::EVENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_bmccontrollers_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_event` timestamp NULL DEFAULT NULL,
                `severity` varchar(16) NOT NULL DEFAULT '',
                `message` text,
                `code` varchar(128) NOT NULL DEFAULT '' COMMENT 'MessageId Redfish',
                `date_read` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_bmccontrollers_id` (`plugin_grcmanager_bmccontrollers_id`),
                KEY `date_event` (`date_event`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        CronTask::Register(
            ServerManagement::class,
            ServerManagement::CRON_NAME,
            DAY_TIMESTAMP,
            [
                'comment' => 'Lit les controleurs de gestion des serveurs par Redfish (lecture seule)',
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
        $migration->dropTable(self::COMPONENTS);
        $migration->dropTable(self::EVENTS);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

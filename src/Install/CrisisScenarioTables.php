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
 * Issue #776 : scénarios de crise sécurité de l'information du PCA (ISO 27001, A.5.29) et
 * processus métier qu'ils touchent. Tables créées si absentes à chaque installation ou mise à jour
 * (rejouable), supprimées à la désinstallation.
 */
final class CrisisScenarioTables
{
    public const SCENARIOS = 'glpi_plugin_grcmanager_crisisscenarios';
    public const PROCESSES = 'glpi_plugin_grcmanager_crisisscenarios_businessprocesses';

    /** Classes propres à ces tables (historique, documents liés : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\CrisisScenario::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::SCENARIOS)) {
            $DB->doQuery("CREATE TABLE `" . self::SCENARIOS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `default_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'Scenario fourni par defaut, vide sinon',
                `name` varchar(255) NOT NULL DEFAULT '',
                `description` text COMMENT 'Situation de crise decrite',
                `degraded_mode` text COMMENT 'Mode degrade securise : acces, validation, communication',
                `role_management` text COMMENT 'Role de la direction',
                `role_ciso` text COMMENT 'Role du RSSI',
                `role_it` text COMMENT 'Role de la DSI',
                `plugin_grcmanager_playbooks_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Guide de reponse',
                `exercise_scenario` varchar(32) NOT NULL DEFAULT '' COMMENT 'Scenario des exercices de crise',
                `communication_situation` varchar(32) NOT NULL DEFAULT ''
                    COMMENT 'Situation des modeles de messages de crise',
                `last_exercise_date` date DEFAULT NULL COMMENT 'Exercice sur table hors plugin',
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `default_key` (`default_key`),
                KEY `plugin_grcmanager_playbooks_id` (`plugin_grcmanager_playbooks_id`),
                KEY `is_active` (`is_active`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::PROCESSES)) {
            $DB->doQuery("CREATE TABLE `" . self::PROCESSES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_crisisscenarios_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_businessprocesses_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_crisisscenarios_id`, `plugin_grcmanager_businessprocesses_id`),
                KEY `plugin_grcmanager_businessprocesses_id` (`plugin_grcmanager_businessprocesses_id`)
            ) {$suffix}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::PROCESSES);
        $migration->dropTable(self::SCENARIOS);
    }
}

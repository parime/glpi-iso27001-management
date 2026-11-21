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
 * Issue #873 : cadrage guidé de la SoA. Réponses au questionnaire de contexte (une par question et
 * par SoA d'entité) et journal des décisions prises sur les propositions (qui, quand, avant, après).
 */
final class SoaScopingTables implements FeatureTables
{
    public const ANSWERS = 'glpi_plugin_grcmanager_soascopinganswers';
    public const DECISIONS = 'glpi_plugin_grcmanager_soascopingdecisions';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::ANSWERS)) {
            $DB->doQuery("CREATE TABLE `" . self::ANSWERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite de la SoA',
                `question` varchar(64) NOT NULL,
                `answer` varchar(8) NOT NULL DEFAULT '' COMMENT 'yes, no',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `question`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
        if (!$DB->tableExists(self::DECISIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::DECISIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite de la SoA',
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL DEFAULT 0,
                `code` varchar(8) NOT NULL DEFAULT '',
                `basis` varchar(64) NOT NULL DEFAULT '' COMMENT 'Question ou baseline',
                `decision` varchar(16) NOT NULL DEFAULT '' COMMENT 'accepted, rejected',
                `proposed_applicability` varchar(16) NOT NULL DEFAULT '',
                `proposed_justification` text,
                `previous_applicability` varchar(16) NOT NULL DEFAULT '',
                `previous_justification` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_grcmanager_controls_id` (`plugin_grcmanager_controls_id`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::ANSWERS);
        $migration->dropTable(self::DECISIONS);
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

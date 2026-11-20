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
 * Issue #599 : registre ReCyF (NIS2 France). Catégorie (importante ou essentielle) réglée par entité
 * GLPI et une fiche par mesure du Référentiel Cyber France pour chaque entité réglée. Tables créées
 * si absentes à chaque installation ou mise à jour (rejouable), supprimées à la désinstallation.
 */
final class RecyfTables
{
    public const ENTITIES = 'glpi_plugin_grcmanager_recyfentities';
    public const REQUIREMENTS = 'glpi_plugin_grcmanager_recyfrequirements';

    /** Classes propres à ces tables (historique, documents liés : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\RecyfRequirement::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::ENTITIES)) {
            $DB->doQuery("CREATE TABLE `" . self::ENTITIES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `category` varchar(16) NOT NULL DEFAULT '' COMMENT 'important, essential',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::REQUIREMENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::REQUIREMENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `code` varchar(16) NOT NULL DEFAULT '' COMMENT 'Mesure du ReCyF, ex. 10.B.3',
                `applicability` varchar(16) NOT NULL DEFAULT 'yes' COMMENT 'yes, partial, no',
                `justification` text,
                `implementation_status` varchar(16) NOT NULL DEFAULT 'not_started'
                    COMMENT 'not_started, in_progress, implemented, verified',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable',
                `due_date` date DEFAULT NULL,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `code`),
                KEY `is_recursive` (`is_recursive`),
                KEY `implementation_status` (`implementation_status`),
                KEY `users_id` (`users_id`),
                KEY `due_date` (`due_date`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::REQUIREMENTS);
        $migration->dropTable(self::ENTITIES);
    }
}

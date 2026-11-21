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
 * Issue #603 : tables de la maturité par mesure (campagnes datées, niveau actuel et niveau cible de
 * chaque mesure), créées si absentes à chaque installation ou mise à jour (rejouable), supprimées à
 * la désinstallation. Les libellés de l'échelle sont dans la configuration du plugin.
 */
final class MaturityCampaignTables
{
    public const CAMPAIGNS = 'glpi_plugin_grcmanager_maturitycampaigns';
    public const ASSESSMENTS = 'glpi_plugin_grcmanager_maturityassessments';

    /** Classes propres à ces tables (historique, documents liés : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\MaturityCampaign::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::CAMPAIGNS)) {
            $DB->doQuery("CREATE TABLE `" . self::CAMPAIGNS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `campaign_date` date DEFAULT NULL COMMENT 'Date de l evaluation',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Pilote de la campagne',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `campaign_date` (`campaign_date`),
                KEY `users_id` (`users_id`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::ASSESSMENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ASSESSMENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_maturitycampaigns_id` int {$keySign} NOT NULL DEFAULT 0,
                `code` varchar(16) NOT NULL DEFAULT '' COMMENT 'Mesure de l Annexe A',
                `current_level` tinyint DEFAULT NULL COMMENT '0 a 5, NULL : non evalue',
                `target_level` tinyint DEFAULT NULL COMMENT '0 a 5, NULL : pas de cible',
                `target_date` date DEFAULT NULL COMMENT 'Echeance de la cible',
                `justification` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable de l atteinte de la cible',
                `plugin_grcmanager_nonconformities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Action ouverte',
                `users_id_lastupdater` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_maturitycampaigns_id`, `code`),
                KEY `users_id` (`users_id`),
                KEY `plugin_grcmanager_nonconformities_id` (`plugin_grcmanager_nonconformities_id`)
            ) {$suffix}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        foreach ([self::ASSESSMENTS, self::CAMPAIGNS] as $table) {
            $migration->dropTable($table);
        }
    }
}

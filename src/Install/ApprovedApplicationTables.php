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
use GlpiPlugin\Grcmanager\ApprovedApplication;
use Migration;

/**
 * Issue #622 : fiche « usage autorisé » d'une application du cœur (Appliance), une ligne au plus par
 * application ; l'entité est celle de l'application. Le registre des applications reste celui du
 * cœur (comme le développement sécurisé, #777) : aucune application en double.
 * Créée si absente (rejouable), supprimée à la désinstallation, avec la tâche de revue périodique.
 */
final class ApprovedApplicationTables implements FeatureTables
{
    public const SHEETS = 'glpi_plugin_grcmanager_approvedapplications';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::SHEETS)) {
            $DB->doQuery("CREATE TABLE `" . self::SHEETS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `status` varchar(16) NOT NULL DEFAULT 'evaluation'
                    COMMENT 'evaluation, approved, restricted, refused',
                `hosting` varchar(16) NOT NULL DEFAULT '' COMMENT 'online, onpremise ; vide : non renseigne',
                `suppliers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Fournisseur du coeur',
                `softwares_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Logiciel du coeur (inventaire)',
                `users_id_owner` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable de l application',
                `user_services` text COMMENT 'Services utilisateurs',
                `date_start` date DEFAULT NULL COMMENT 'Debut d usage',
                `date_end` date DEFAULT NULL COMMENT 'Fin d usage',
                `allowed_uses` text COMMENT 'Cas d usage autorises',
                `restrictions` text COMMENT 'Restrictions (approuvee avec restrictions)',
                `max_data_level` varchar(8) NOT NULL DEFAULT ''
                    COMMENT 'Confidentialite maximale des donnees : low, medium, high',
                `waivers` text COMMENT 'Derogations accordees (identifiants, JSON)',
                `review_months` int NOT NULL DEFAULT 12 COMMENT 'Cycle de revue en mois, 0 : aucun',
                `last_review_date` date DEFAULT NULL,
                `review_tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Ticket de reevaluation ouvert',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `appliances_id` (`appliances_id`),
                KEY `status` (`status`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `softwares_id` (`softwares_id`),
                KEY `users_id_owner` (`users_id_owner`),
                KEY `review_tickets_id` (`review_tickets_id`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        CronTask::Register(
            ApprovedApplication::class,
            ApprovedApplication::CRON_NAME,
            DAY_TIMESTAMP,
            [
                'comment' => 'Ouvre un ticket de reevaluation des applications approuvees a l echeance de leur revue',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::SHEETS);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [ApprovedApplication::class];
    }
}

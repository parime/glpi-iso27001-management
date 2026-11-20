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
use Migration;

/**
 * Issue #778 : tables de l'évaluation de la sécurité physique des sites (ISO 27001, A.7.1 à A.7.14),
 * créées si absentes à chaque installation ou mise à jour (rejouable), supprimées à la
 * désinstallation.
 */
final class PhysicalSecurityTables
{
    public const ASSESSMENTS = 'glpi_plugin_grcmanager_physicalsecurityassessments';
    public const CHECKS = 'glpi_plugin_grcmanager_physicalsecuritychecks';
    public const CHECK_DOCUMENTS = 'glpi_plugin_grcmanager_physicalsecuritychecks_documents';
    public const ZONES = 'glpi_plugin_grcmanager_physicalsecurityzones';

    /** Classes propres à ces tables (historique, documents liés : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\PhysicalSecurityAssessment::class,
        \GlpiPlugin\Grcmanager\NotificationTargetPhysicalSecurityAssessment::class,
        \GlpiPlugin\Grcmanager\PhysicalSecurity::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::ASSESSMENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ASSESSMENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `locations_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Site evalue (lieu de GLPI)',
                `status` varchar(16) NOT NULL DEFAULT 'in_progress' COMMENT 'in_progress, completed',
                `assessment_date` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Evaluateur, destinataire du rappel',
                `plugin_grcmanager_policies_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Politique de securite physique et environnementale',
                `next_assessment_date` date DEFAULT NULL COMMENT 'Reevaluation annuelle du site',
                `reminder_date` timestamp NULL DEFAULT NULL COMMENT 'Dernier rappel de reevaluation',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `locations_id` (`locations_id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `plugin_grcmanager_policies_id` (`plugin_grcmanager_policies_id`),
                KEY `assessment_date` (`assessment_date`),
                KEY `next_assessment_date` (`next_assessment_date`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::CHECKS)) {
            $DB->doQuery("CREATE TABLE `" . self::CHECKS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_physicalsecurityassessments_id` int {$keySign} NOT NULL DEFAULT 0,
                `code` varchar(16) NOT NULL DEFAULT '' COMMENT 'Mesure A.7.x',
                `state` varchar(16) NOT NULL DEFAULT 'not_assessed'
                    COMMENT 'not_assessed, compliant, partial, noncompliant, not_applicable',
                `finding` text COMMENT 'Constat',
                `evidence` text COMMENT 'Preuves examinees',
                `plugin_grcmanager_nonconformities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Action CAPA',
                `users_id_lastupdater` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_physicalsecurityassessments_id`, `code`),
                KEY `state` (`state`),
                KEY `plugin_grcmanager_nonconformities_id` (`plugin_grcmanager_nonconformities_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::CHECK_DOCUMENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::CHECK_DOCUMENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_physicalsecuritychecks_id` int {$keySign} NOT NULL DEFAULT 0,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_physicalsecuritychecks_id`, `documents_id`),
                KEY `documents_id` (`documents_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::ZONES)) {
            $DB->doQuery("CREATE TABLE `" . self::ZONES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_physicalsecurityassessments_id` int {$keySign} NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `zone_type` varchar(16) NOT NULL DEFAULT 'controlled'
                    COMMENT 'public, controlled, sensitive, technical',
                `access_means` text COMMENT 'Moyens de controle d acces (JSON)',
                `authorized_people` text COMMENT 'Personnes autorisees',
                `instructions_posted` tinyint NOT NULL DEFAULT 0 COMMENT 'Consignes d acces affichees',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `assessment` (`plugin_grcmanager_physicalsecurityassessments_id`),
                KEY `zone_type` (`zone_type`)
            ) {$suffix}");
        }

        // Rappel de réévaluation annuelle des sites.
        CronTask::Register(
            \GlpiPlugin\Grcmanager\PhysicalSecurityAssessment::class,
            'reassessmentreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Rappelle a l evaluateur la reevaluation annuelle de la securite physique de chaque site',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );
    }

    public static function uninstall(Migration $migration): void
    {
        foreach ([self::CHECK_DOCUMENTS, self::CHECKS, self::ZONES, self::ASSESSMENTS] as $table) {
            $migration->dropTable($table);
        }
    }
}

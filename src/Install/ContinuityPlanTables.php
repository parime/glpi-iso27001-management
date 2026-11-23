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
 * Issue #1083 : plans de continuité et de reprise (PCA, PRA, PCI, PRI), ISO 27001 A.5.29 et A.5.30.
 * - plans : contenu structuré, statut du circuit d'approbation, dates d'approbation et de revue ;
 * - éléments couverts : processus métier, scénarios de crise, fournisseurs (liens, rien n'est copié) ;
 * - versions soumises à l'approbation, figées (contenu en JSON et empreinte SHA-256), et réponses des
 *   approbateurs ;
 * - tests du plan (exercice, test de reprise), avec leur résultat et l'action créée depuis un écart.
 * Tables créées si absentes (rejouable), supprimées à la désinstallation.
 */
final class ContinuityPlanTables implements FeatureTables
{
    public const PLANS = 'glpi_plugin_grcmanager_continuityplans';
    public const ITEMS = 'glpi_plugin_grcmanager_continuityplans_items';
    public const VERSIONS = 'glpi_plugin_grcmanager_continuityplanversions';
    public const APPROVERS = 'glpi_plugin_grcmanager_continuityplanversions_users';
    public const TESTS = 'glpi_plugin_grcmanager_continuityplantests';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::PLANS)) {
            $DB->doQuery("CREATE TABLE `" . self::PLANS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `plan_type` varchar(8) NOT NULL DEFAULT 'pca' COMMENT 'pca, pra, pci ou pri',
                `version` varchar(16) NOT NULL DEFAULT '1.0',
                `status` varchar(16) NOT NULL DEFAULT 'draft' COMMENT 'draft, pending ou approved',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable du plan',
                `users_id_approver` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Approbateur designe',
                `groups_id_crisis` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Cellule de crise',
                `groups_id_oncall` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Astreinte',
                `scope` text COMMENT 'Perimetre : entites, services, sites',
                `activation_criteria` text COMMENT 'Criteres de declenchement',
                `activation_authority` text COMMENT 'Qui declenche le plan',
                `objectives` text COMMENT 'Objectifs de reprise complementaires au BIA',
                `roles` text COMMENT 'Roles et contacts',
                `procedures` text COMMENT 'Etapes du plan',
                `fallback` text COMMENT 'Site de secours, mode degrade, solutions de repli',
                `dependencies` text COMMENT 'Dependances et ordre de reprise',
                `communication` text COMMENT 'Communication interne et externe',
                `review_months` int NOT NULL DEFAULT 0 COMMENT 'Periodicite de revue, 0 : reglage par defaut',
                `date_approved` date DEFAULT NULL,
                `next_review_date` date DEFAULT NULL,
                `comment` text,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `plan_type` (`plan_type`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `users_id_approver` (`users_id_approver`),
                KEY `groups_id_crisis` (`groups_id_crisis`),
                KEY `groups_id_oncall` (`groups_id_oncall`),
                KEY `next_review_date` (`next_review_date`),
                KEY `is_deleted` (`is_deleted`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::ITEMS)) {
            $DB->doQuery("CREATE TABLE `" . self::ITEMS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_continuityplans_id` int {$keySign} NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_continuityplans_id`, `itemtype`, `items_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::VERSIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::VERSIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_continuityplans_id` int {$keySign} NOT NULL DEFAULT 0,
                `version` varchar(16) NOT NULL DEFAULT '',
                `status` varchar(16) NOT NULL DEFAULT 'pending'
                    COMMENT 'pending, approved, rejected ou withdrawn',
                `quorum` int NOT NULL DEFAULT 0 COMMENT '0 : tous les approbateurs',
                `snapshot` longtext COMMENT 'Contenu fige (JSON)',
                `fingerprint` varchar(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 du contenu fige',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Demandeur',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_answered` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_continuityplans_id` (`plugin_grcmanager_continuityplans_id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::APPROVERS)) {
            $DB->doQuery("CREATE TABLE `" . self::APPROVERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_continuityplanversions_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `status` varchar(16) NOT NULL DEFAULT 'pending',
                `comment` text,
                `date_answered` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_continuityplanversions_id`, `users_id`),
                KEY `users_id` (`users_id`),
                KEY `status` (`status`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::TESTS)) {
            $DB->doQuery("CREATE TABLE `" . self::TESTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_continuityplans_id` int {$keySign} NOT NULL DEFAULT 0,
                `test_date` date DEFAULT NULL,
                `test_type` varchar(64) NOT NULL DEFAULT '',
                `result` varchar(16) NOT NULL DEFAULT '' COMMENT 'passed, partial ou failed',
                `plugin_grcmanager_crisisexercises_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Exercice de crise du plugin',
                `summary` text,
                `gaps` text COMMENT 'Ecarts constates',
                `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'Action creee depuis les ecarts',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_continuityplans_id` (`plugin_grcmanager_continuityplans_id`),
                KEY `test_date` (`test_date`),
                KEY `plugin_grcmanager_crisisexercises_id` (`plugin_grcmanager_crisisexercises_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) {$suffix}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::TESTS);
        $migration->dropTable(self::APPROVERS);
        $migration->dropTable(self::VERSIONS);
        $migration->dropTable(self::ITEMS);
        $migration->dropTable(self::PLANS);
    }

    public static function pluginClasses(): array
    {
        return [\GlpiPlugin\Grcmanager\ContinuityPlan::class];
    }
}

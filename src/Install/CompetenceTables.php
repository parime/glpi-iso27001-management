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
 * Issue #774 : tables des compétences (ISO 27001, article 7.2), créées si absentes à chaque
 * installation ou mise à jour (rejouable), supprimées à la désinstallation.
 */
final class CompetenceTables
{
    public const FUNCTIONS = 'glpi_plugin_grcmanager_ismsfunctions';
    public const HOLDERS = 'glpi_plugin_grcmanager_ismsfunctions_holders';
    public const COMPETENCES = 'glpi_plugin_grcmanager_competences';
    public const REQUIREMENTS = 'glpi_plugin_grcmanager_ismsfunctions_competences';
    public const TRAININGS = 'glpi_plugin_grcmanager_competences_trainings';
    public const DECLARATIONS = 'glpi_plugin_grcmanager_competencedeclarations';
    public const EXPORTS = 'glpi_plugin_grcmanager_competenceexports';

    /** Classes propres à ces tables (historique, documents liés : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\IsmsFunction::class,
        \GlpiPlugin\Grcmanager\Competence::class,
        \GlpiPlugin\Grcmanager\CompetenceDeclaration::class,
        \GlpiPlugin\Grcmanager\CompetenceExport::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::FUNCTIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::FUNCTIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `default_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'Fonction proposee par defaut, vide sinon',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Vide : libelle traduit de la fonction par defaut',
                `description` text COMMENT 'Mission de la fonction dans le SMSI',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `default_key` (`default_key`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::HOLDERS)) {
            $DB->doQuery("CREATE TABLE `" . self::HOLDERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_ismsfunctions_id` int {$keySign} NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'User, Group ou UserTitle',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_ismsfunctions_id`, `itemtype`, `items_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::COMPETENCES)) {
            $DB->doQuery("CREATE TABLE `" . self::COMPETENCES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `default_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'Competence proposee par defaut, vide sinon',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Vide : libelle traduit de la competence par defaut',
                `description` text,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `default_key` (`default_key`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::REQUIREMENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::REQUIREMENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_ismsfunctions_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_competences_id` int {$keySign} NOT NULL DEFAULT 0,
                `required_level` tinyint NOT NULL DEFAULT 1 COMMENT '1 notions, 2 maitrise, 3 expertise',
                `acquisition_mode` varchar(16) NOT NULL DEFAULT 'training'
                    COMMENT 'training, diploma, certification, experience, other',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_ismsfunctions_id`, `plugin_grcmanager_competences_id`),
                KEY `plugin_grcmanager_competences_id` (`plugin_grcmanager_competences_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::TRAININGS)) {
            $DB->doQuery("CREATE TABLE `" . self::TRAININGS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_competences_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_trainings_id` int {$keySign} NOT NULL DEFAULT 0,
                `level` tinyint NOT NULL DEFAULT 1 COMMENT 'Niveau donne par la formation reussie',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_competences_id`, `plugin_grcmanager_trainings_id`),
                KEY `plugin_grcmanager_trainings_id` (`plugin_grcmanager_trainings_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::DECLARATIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::DECLARATIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_competences_id` int {$keySign} NOT NULL DEFAULT 0,
                `level` tinyint NOT NULL DEFAULT 1,
                `evidence_type` varchar(16) NOT NULL DEFAULT 'certificate'
                    COMMENT 'certificate, attestation, diploma, annual_review, experience, other',
                `evidence_reference` varchar(255) NOT NULL DEFAULT '' COMMENT 'Reference de la preuve',
                `date_acquired` date DEFAULT NULL,
                `date_expiry` date DEFAULT NULL,
                `users_id_recorder` int {$keySign} NOT NULL DEFAULT 0,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `users_id` (`users_id`),
                KEY `plugin_grcmanager_competences_id` (`plugin_grcmanager_competences_id`),
                KEY `date_expiry` (`date_expiry`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::EXPORTS)) {
            $DB->doQuery("CREATE TABLE `" . self::EXPORTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'PDF archive',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `documents_id` (`documents_id`),
                KEY `users_id` (`users_id`)
            ) {$suffix}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        foreach (
            [
                self::HOLDERS, self::REQUIREMENTS, self::TRAININGS, self::DECLARATIONS, self::EXPORTS,
                self::FUNCTIONS, self::COMPETENCES,
            ] as $table
        ) {
            $migration->dropTable($table);
        }
    }
}

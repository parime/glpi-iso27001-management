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
 * Issue #777 : tables du développement sécurisé (mesures A.8.25 à A.8.31), créées si absentes à
 * chaque installation ou mise à jour (rejouable), supprimées à la désinstallation.
 *
 * - Fiche « développement sécurisé » d'une application du cœur (Appliance) : une ligne au plus par
 *   application, l'entité est celle de l'application.
 * - Validation de mise en production d'un changement du cœur (Change) : une ligne au plus par
 *   changement, l'entité est celle du changement.
 */
final class SecureDevelopmentTables
{
    public const APPLICATIONS = 'glpi_plugin_grcmanager_applicationsecurities';
    public const RELEASES = 'glpi_plugin_grcmanager_releasevalidations';

    /** Classes propres à ces tables (historique : nettoyé à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\ApplicationSecurity::class,
        \GlpiPlugin\Grcmanager\ReleaseValidation::class,
        \GlpiPlugin\Grcmanager\SecureDevelopment::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::APPLICATIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::APPLICATIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id_owner` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Responsable du developpement securise, 0 : responsable technique de l application',
                `repository_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'Depot de code (http, https, ssh)',
                `framework` varchar(32) NOT NULL DEFAULT ''
                    COMMENT 'owasp_asvs, owasp_top10, owasp_samm, anssi, other ; vide : aucun',
                `framework_detail` varchar(255) NOT NULL DEFAULT '' COMMENT 'Niveau ou version du referentiel',
                `code_review_required` tinyint NOT NULL DEFAULT 0,
                `sast` tinyint NOT NULL DEFAULT 0 COMMENT 'Analyse statique du code',
                `dast` tinyint NOT NULL DEFAULT 0 COMMENT 'Analyse dynamique de l application',
                `dependency_scan` tinyint NOT NULL DEFAULT 0 COMMENT 'Analyse des dependances',
                `tools` text COMMENT 'Outils et frequence des analyses',
                `environments_separated` varchar(8) NOT NULL DEFAULT '' COMMENT 'yes, no ; vide : non renseigne',
                `separation_justification` text COMMENT 'Separation des acces, donnees, configuration, ou motif',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `appliances_id` (`appliances_id`),
                KEY `users_id_owner` (`users_id_owner`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::RELEASES)) {
            $DB->doQuery("CREATE TABLE `" . self::RELEASES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `changes_id` int {$keySign} NOT NULL DEFAULT 0,
                `tests_passed` tinyint NOT NULL DEFAULT 0,
                `review_done` tinyint NOT NULL DEFAULT 0,
                `vulnerabilities_treated` tinyint NOT NULL DEFAULT 0,
                `checklist_comment` text COMMENT 'Preuves de la liste de controle (rapports, liens)',
                `decision` varchar(16) NOT NULL DEFAULT '' COMMENT 'approved, rejected ; vide : en attente',
                `validator_role` varchar(8) NOT NULL DEFAULT '' COMMENT 'cio (DSI) ou ciso (RSSI)',
                `decision_comment` text COMMENT 'Motif (obligatoire pour un refus)',
                `users_id_decision` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la decision',
                `date_decision` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `changes_id` (`changes_id`),
                KEY `decision` (`decision`),
                KEY `users_id_decision` (`users_id_decision`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
    }

    /**
     * Lot 2 de #777 : détail de la séparation des environnements, exigences de sécurité (A.8.26),
     * principes d'architecture (A.8.27) et développement externalisé (A.8.30), ajoutés aux fiches
     * existantes sans toucher à leurs données (rejouable).
     */
    public static function upgrade(Migration $migration): void
    {
        global $DB;

        $table = self::APPLICATIONS;
        if (!$DB->tableExists($table) || $DB->fieldExists($table, 'separation_access')) {
            return;
        }
        $after = 'environments_separated';
        foreach (['separation_access', 'separation_data', 'separation_config'] as $field) {
            $migration->addField($table, $field, 'bool', ['value' => 0, 'after' => $after]);
            $after = $field;
        }
        $migration->addField($table, 'security_requirements', 'text', [
            'comment' => 'Exigences de securite de l application (A.8.26)',
            'after'   => 'separation_justification',
        ]);
        $migration->addField($table, 'architecture_principles', 'text', [
            'comment' => 'Principes d architecture securisee (A.8.27)',
            'after'   => 'security_requirements',
        ]);
        $migration->addField($table, 'outsourced', 'bool', ['value' => 0, 'after' => 'architecture_principles']);
        $migration->addField($table, 'suppliers_id', 'fkey', ['after' => 'outsourced']);
        $migration->addField($table, 'outsourcing_clauses', 'bool', [
            'value'   => 0,
            'comment' => 'Clauses de securite au contrat du prestataire',
            'after'   => 'suppliers_id',
        ]);
        $migration->addField($table, 'outsourcing_oversight', 'text', [
            'comment' => 'Suivi du prestataire : revue, recette, droit d audit',
            'after'   => 'outsourcing_clauses',
        ]);
        $migration->addKey($table, 'suppliers_id');
        $migration->migrationOneTable($table);
    }

    public static function uninstall(Migration $migration): void
    {
        foreach ([self::APPLICATIONS, self::RELEASES] as $table) {
            $migration->dropTable($table);
        }
    }
}

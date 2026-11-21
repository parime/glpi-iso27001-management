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

use Config;
use Migration;

/**
 * Issue #865, lot 2 : réponse du fournisseur par lien sécurisé, sans compte GLPI.
 * - liens : un jeton par lien, seule son empreinte SHA-256 est gardée ; une seule évaluation,
 *   expiration, nombre d'envois limité, révocable ;
 * - brouillons : réponses envoyées par le fournisseur, relues par un utilisateur GLPI avant d'être
 *   enregistrées dans l'évaluation (pièces gardées hors des documents GLPI jusque-là) ;
 * - compteurs de débit de la page publique (par adresse IP et par jeton, empreintes seulement).
 * Créées si absentes (rejouable), supprimées à la désinstallation avec les pièces en attente.
 */
final class SupplierPortalTables implements FeatureTables
{
    public const LINKS = 'glpi_plugin_grcmanager_supplierportallinks';
    public const DRAFTS = 'glpi_plugin_grcmanager_supplierportaldrafts';
    public const THROTTLES = 'glpi_plugin_grcmanager_supplierportalthrottles';
    public const LINK_FK = 'plugin_grcmanager_supplierportallinks_id';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $evaluationFk = SupplierQuestionnaireTables::EVALUATION_FK;
        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::LINKS)) {
            $DB->doQuery("CREATE TABLE `" . self::LINKS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `{$evaluationFk}` int {$keySign} NOT NULL DEFAULT 0,
                `token_hash` char(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 du jeton, jamais le jeton',
                `fingerprint` varchar(64) NOT NULL DEFAULT '' COMMENT 'Empreinte des questions a la creation',
                `max_uses` int NOT NULL DEFAULT 1 COMMENT 'Envois autorises',
                `uses` int NOT NULL DEFAULT 0 COMMENT 'Envois recus',
                `access_count` int NOT NULL DEFAULT 0,
                `date_last_access` timestamp NULL DEFAULT NULL,
                `date_expiration` timestamp NULL DEFAULT NULL,
                `is_revoked` tinyint NOT NULL DEFAULT 0,
                `date_revoked` timestamp NULL DEFAULT NULL,
                `users_id_revoke` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Createur du lien',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token_hash` (`token_hash`),
                KEY `{$evaluationFk}` (`{$evaluationFk}`),
                KEY `users_id` (`users_id`),
                KEY `users_id_revoke` (`users_id_revoke`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::DRAFTS)) {
            $linkFk = self::LINK_FK;
            $DB->doQuery("CREATE TABLE `" . self::DRAFTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `{$linkFk}` int {$keySign} NOT NULL DEFAULT 0,
                `{$evaluationFk}` int {$keySign} NOT NULL DEFAULT 0,
                `fingerprint` varchar(64) NOT NULL DEFAULT '',
                `answers` longtext COMMENT 'JSON : id de la reponse => reponse et commentaire',
                `files` longtext COMMENT 'JSON : id de la reponse => piece en attente',
                `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, applied, discarded',
                `users_id_review` int {$keySign} NOT NULL DEFAULT 0,
                `date_review` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `{$linkFk}` (`{$linkFk}`),
                KEY `{$evaluationFk}` (`{$evaluationFk}`),
                KEY `status` (`status`),
                KEY `users_id_review` (`users_id_review`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::THROTTLES)) {
            $DB->doQuery("CREATE TABLE `" . self::THROTTLES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `bucket` char(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 de l adresse IP ou du jeton',
                `hits` int NOT NULL DEFAULT 0,
                `window_start` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `bucket` (`bucket`),
                KEY `window_start` (`window_start`)
            ) {$suffix}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::LINKS);
        $migration->dropTable(self::DRAFTS);
        $migration->dropTable(self::THROTTLES);
        Config::deleteConfigurationValues('plugin:grcmanager', ['supplier_portal_enabled']);
        $dir = \GlpiPlugin\Grcmanager\SupplierEvaluationLink::storageDir();
        foreach (glob($dir . '/*.bin') ?: [] as $file) {
            // Pièces en attente créées par le plugin sous un nom aléatoire, dans son propre dossier.
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            @unlink($file);
        }
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

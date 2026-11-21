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
 * Issue #616 : communication de crise (modèles de messages, liste de diffusion de chaque modèle,
 * communications faites depuis un incident ou une violation). Tables créées si absentes à chaque
 * installation ou mise à jour (rejouable), supprimées à la désinstallation.
 */
final class CrisisCommunicationTables
{
    public const TEMPLATES = 'glpi_plugin_grcmanager_crisismessagetemplates';
    public const RECIPIENTS = 'glpi_plugin_grcmanager_crisismessagerecipients';
    public const COMMUNICATIONS = 'glpi_plugin_grcmanager_crisiscommunications';

    /** Classes propres à ces tables (historique, préférences d'affichage : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\CrisisMessageTemplate::class,
        \GlpiPlugin\Grcmanager\CrisisMessageRecipient::class,
        \GlpiPlugin\Grcmanager\CrisisCommunication::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        $suffix = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->tableExists(self::TEMPLATES)) {
            $DB->doQuery("CREATE TABLE `" . self::TEMPLATES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `situation` varchar(32) NOT NULL DEFAULT 'major_incident'
                    COMMENT 'major_incident, data_breach, unavailability, ransomware, other',
                `stage` varchar(16) NOT NULL DEFAULT 'first' COMMENT 'first, update, end',
                `audience` varchar(16) NOT NULL DEFAULT 'internal' COMMENT 'internal, external',
                `subject` varchar(255) NOT NULL DEFAULT '',
                `body` text COMMENT 'Texte brut, variables entre accolades',
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `situation` (`situation`),
                KEY `is_active` (`is_active`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::RECIPIENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::RECIPIENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_crisismessagetemplates_id` int {$keySign} NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'User, Group ou contact officiel (ContextItem)',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_crisismessagetemplates_id`, `itemtype`, `items_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::COMMUNICATIONS)) {
            $DB->doQuery("CREATE TABLE `" . self::COMMUNICATIONS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite de l incident ou de la violation',
                `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'Incident de securite ou violation de donnees',
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_crisismessagetemplates_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT '0 = message libre ou modele supprime',
                `template_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom du modele au moment de l envoi',
                `subject` varchar(255) NOT NULL DEFAULT '',
                `body` text COMMENT 'Message envoye par la personne (texte brut)',
                `recipients` text COMMENT 'Destinataires, un par ligne (copie au moment de l envoi)',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la communication',
                `date_communication` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `plugin_grcmanager_crisismessagetemplates_id` (`plugin_grcmanager_crisismessagetemplates_id`),
                KEY `users_id` (`users_id`),
                KEY `date_communication` (`date_communication`)
            ) {$suffix}");
        }
    }

    /**
     * Issue #616, lot 2 : suppléant d'un destinataire (autre ligne de la même liste de diffusion) et
     * revue annuelle de la liste de diffusion de chaque modèle. Colonnes ajoutées si absentes.
     */
    public static function migrate(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::RECIPIENTS) && !$DB->fieldExists(self::RECIPIENTS, 'deputy_of')) {
            $migration->addField(self::RECIPIENTS, 'deputy_of', 'fkey', [
                'comment' => 'Ligne du destinataire principal dont celui-ci est le suppleant, 0 sinon',
                'after'   => 'items_id',
            ]);
            $migration->addKey(self::RECIPIENTS, 'deputy_of');
            $migration->migrationOneTable(self::RECIPIENTS);
        }
        if ($DB->tableExists(self::TEMPLATES) && !$DB->fieldExists(self::TEMPLATES, 'last_review_date')) {
            $migration->addField(self::TEMPLATES, 'last_review_date', 'date', [
                'comment' => 'Derniere revue de la liste de diffusion',
                'after'   => 'is_active',
            ]);
            $migration->addField(self::TEMPLATES, 'users_id_lastreviewer', 'fkey', [
                'after' => 'last_review_date',
            ]);
            $migration->addKey(self::TEMPLATES, 'last_review_date');
            $migration->addKey(self::TEMPLATES, 'users_id_lastreviewer');
            $migration->migrationOneTable(self::TEMPLATES);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        foreach ([self::RECIPIENTS, self::COMMUNICATIONS, self::TEMPLATES] as $table) {
            $migration->dropTable($table);
        }
    }
}

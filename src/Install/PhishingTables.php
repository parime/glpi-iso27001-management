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
 * Issue #604 : campagnes de simulation d'hameçonnage (faites avec un outil externe) et leurs
 * résultats importés par personne. Les compteurs de la campagne gardent les taux une fois les
 * résultats individuels anonymisés. Tables créées si absentes (rejouable), supprimées à la
 * désinstallation.
 */
final class PhishingTables
{
    public const CAMPAIGNS = 'glpi_plugin_grcmanager_phishingcampaigns';
    public const RESULTS = 'glpi_plugin_grcmanager_phishingresults';

    /** Classes propres à ces tables (historique, préférences d'affichage : nettoyés à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\PhishingCampaign::class,
        \GlpiPlugin\Grcmanager\PhishingResult::class,
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
                `campaign_date` date DEFAULT NULL,
                `tool` varchar(255) NOT NULL DEFAULT '' COMMENT 'Outil de simulation, texte libre',
                `scenario` text COMMENT 'Scenario du message piege',
                `target_population` text COMMENT 'Population ciblee, texte libre',
                `comment` text,
                `nb_sent` int NOT NULL DEFAULT 0,
                `nb_opened` int NOT NULL DEFAULT 0,
                `nb_clicked` int NOT NULL DEFAULT 0,
                `nb_submitted` int NOT NULL DEFAULT 0,
                `nb_reported` int NOT NULL DEFAULT 0,
                `nb_unmatched` int NOT NULL DEFAULT 0 COMMENT 'Lignes sans utilisateur GLPI',
                `group_counts` text COMMENT 'JSON - compteurs anonymes par groupe, gardes apres anonymisation',
                `import_file` varchar(255) NOT NULL DEFAULT '' COMMENT 'Nom du dernier fichier importe',
                `date_import` timestamp NULL DEFAULT NULL,
                `date_anonymized` timestamp NULL DEFAULT NULL
                    COMMENT 'Resultats individuels supprimes, compteurs gardes',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `campaign_date` (`campaign_date`),
                KEY `date_mod` (`date_mod`)
            ) {$suffix}");
        }
        if (!$DB->tableExists(self::RESULTS)) {
            $DB->doQuery("CREATE TABLE `" . self::RESULTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_phishingcampaigns_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT '0 = ligne non rapprochee',
                `identifier` varchar(255) NOT NULL DEFAULT ''
                    COMMENT 'Adresse ou identifiant du fichier, garde seulement si non rapproche',
                `line` int NOT NULL DEFAULT 0 COMMENT 'Ligne du fichier importe',
                `is_sent` tinyint NOT NULL DEFAULT 0,
                `is_opened` tinyint NOT NULL DEFAULT 0,
                `is_clicked` tinyint NOT NULL DEFAULT 0,
                `is_submitted` tinyint NOT NULL DEFAULT 0,
                `is_reported` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_phishingcampaigns_id` (`plugin_grcmanager_phishingcampaigns_id`),
                KEY `users_id` (`users_id`)
            ) {$suffix}");
        }
    }

    /**
     * Issue #604, lot 2 : compteurs par service d'une table créée par le lot 1. Vides pour les
     * campagnes déjà importées : un nouvel import les calcule.
     */
    public static function migrate(Migration $migration): void
    {
        global $DB;

        if ($DB->tableExists(self::CAMPAIGNS) && !$DB->fieldExists(self::CAMPAIGNS, 'group_counts', false)) {
            $migration->addField(self::CAMPAIGNS, 'group_counts', 'text', [
                'after'   => 'nb_unmatched',
                'comment' => 'JSON - compteurs anonymes par groupe, gardes apres anonymisation',
            ]);
            $migration->migrationOneTable(self::CAMPAIGNS);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        foreach ([self::RESULTS, self::CAMPAIGNS] as $table) {
            $migration->dropTable($table);
        }
    }
}

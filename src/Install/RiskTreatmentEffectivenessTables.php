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
 * Issue #1182 : efficacité du traitement des risques.
 *
 * - table des revues de risque : une ligne par revue, avec l'instantané de la cotation au moment de
 *   la revue (probabilité, impact, niveaux et scores initial et résiduel, risque cible, traitement,
 *   avancement du plan), jamais modifiée ensuite ;
 * - deux colonnes du registre des risques : le niveau cible du traitement et la date d'expiration
 *   de l'acceptation signée.
 *
 * Nom de fichier choisi pour passer après RiskResidualScoreTables (ordre alphabétique de
 * FeatureTablesRegistry). Colonnes ajoutées si elles manquent (rejouable), à l'installation comme à
 * la mise à jour ; la table des risques disparaît à la désinstallation (Installer::uninstall()),
 * colonnes comprises.
 */
final class RiskTreatmentEffectivenessTables implements FeatureTables
{
    public const RISKS   = 'glpi_plugin_grcmanager_risks';
    public const REVIEWS = 'glpi_plugin_grcmanager_riskreviews';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if ($DB->tableExists(self::REVIEWS)) {
            return;
        }
        $DB->doQuery("CREATE TABLE `" . self::REVIEWS . "` (
            `id` int {$keySign} NOT NULL AUTO_INCREMENT,
            `plugin_grcmanager_risks_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Risque revu',
            `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite du risque au moment de la revue',
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la revue',
            `date_review` timestamp NULL DEFAULT NULL COMMENT 'Date de la revue',
            `probability` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : probabilite initiale',
            `impact` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : impact initial',
            `risk_level` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : niveau initial',
            `computed_score` decimal(5,2) NOT NULL DEFAULT 0 COMMENT 'Instantane : score initial',
            `residual_probability` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : probabilite residuelle',
            `residual_impact` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : impact residuel',
            `residual_level` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : niveau residuel, vide si non evalue',
            `residual_score` decimal(5,2) DEFAULT NULL COMMENT 'Instantane : score residuel, vide si non evalue',
            `target_level` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : niveau cible, vide si non defini',
            `treatment` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : traitement',
            `status` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : statut du risque',
            `actions_total` int NOT NULL DEFAULT 0 COMMENT 'Instantane : actions du plan de traitement',
            `actions_done` int NOT NULL DEFAULT 0 COMMENT 'Instantane : actions terminees',
            `effectiveness` varchar(16) NOT NULL DEFAULT '' COMMENT 'Instantane : efficacite du traitement',
            `comment` text COMMENT 'Constat de la revue',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_grcmanager_risks_id` (`plugin_grcmanager_risks_id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `date_review` (`date_review`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    public static function migrate(Migration $migration): void
    {
        global $DB;

        if (!$DB->tableExists(self::RISKS)) {
            return;
        }
        $changed = false;
        if (!$DB->fieldExists(self::RISKS, 'target_level')) {
            $migration->addField(self::RISKS, 'target_level', "varchar(16) NOT NULL DEFAULT ''", [
                'nodefault' => true,
                'comment'   => 'Niveau cible du traitement : low, medium, high, critical, vide si non defini',
            ]);
            $migration->addKey(self::RISKS, 'target_level');
            $changed = true;
        }
        if (!$DB->fieldExists(self::RISKS, 'date_acceptance_expiry')) {
            $migration->addField(self::RISKS, 'date_acceptance_expiry', 'date DEFAULT NULL', [
                'nodefault' => true,
                'comment'   => 'Fin de validite de l acceptation signee, a renouveler ensuite',
            ]);
            $changed = true;
        }
        if ($changed) {
            $migration->migrationOneTable(self::RISKS);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::REVIEWS);
    }

    public static function pluginClasses(): array
    {
        return [\GlpiPlugin\Grcmanager\RiskReview::class];
    }
}

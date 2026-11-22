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
 * Issue #872 : risques signalés par les collaborateurs, puis triés par le responsable des risques.
 * Table créée si absente à chaque installation ou mise à jour (rejouable), supprimée à la
 * désinstallation.
 *
 * Une ligne par signalement : l'auteur, l'entité active au moment du signalement, le titre, la
 * description, la gravité ressentie ; puis la décision du tri (acceptée, rattachée, rejetée), son
 * auteur, sa date, son motif et le risque du registre créé ou retenu.
 */
final class ReportedRiskTables implements FeatureTables
{
    public const REPORTS = 'glpi_plugin_grcmanager_reportedrisks';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if ($DB->tableExists(self::REPORTS)) {
            return;
        }
        $DB->doQuery("CREATE TABLE `" . self::REPORTS . "` (
            `id` int {$keySign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite active de l auteur au signalement',
            `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Ce que la personne a remarque',
            `description` text COMMENT 'Situation decrite par la personne',
            `severity` varchar(16) NOT NULL DEFAULT '' COMMENT 'Gravite ressentie : low, medium, high, vide',
            `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur du signalement',
            `status` varchar(16) NOT NULL DEFAULT 'new' COMMENT 'new, accepted, merged, rejected',
            `plugin_grcmanager_risks_id` int {$keySign} NOT NULL DEFAULT 0
                COMMENT 'Risque cree (accepte) ou existant (rattache), 0 sinon',
            `decision_comment` text COMMENT 'Motif du rejet ou message a l auteur',
            `users_id_decision` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la decision',
            `date_decision` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `plugin_grcmanager_risks_id` (`plugin_grcmanager_risks_id`),
            KEY `users_id_decision` (`users_id_decision`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::REPORTS);
    }

    public static function pluginClasses(): array
    {
        return [
            \GlpiPlugin\Grcmanager\ReportedRisk::class,
            \GlpiPlugin\Grcmanager\NotificationTargetReportedRisk::class,
        ];
    }
}

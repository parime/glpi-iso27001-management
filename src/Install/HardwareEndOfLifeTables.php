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
 * Issue #782, lot 2 : date de fin de vie déclarée par le constructeur, saisie à la main par modèle
 * de matériel (ComputerModel, MonitorModel...). Rien n'est écrit dans les tables de modèles de GLPI :
 * la date vit dans cette table du plugin. Créée si absente (rejouable), supprimée à la
 * désinstallation.
 */
final class HardwareEndOfLifeTables implements FeatureTables
{
    public const MODELS = 'glpi_plugin_grcmanager_hardwaremodeleols';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::MODELS)) {
            $DB->doQuery("CREATE TABLE `" . self::MODELS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'Classe du modele (ComputerModel...)',
                `items_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Identifiant du modele',
                `end_of_life_date` date DEFAULT NULL COMMENT 'Fin de vie declaree par le constructeur',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la saisie',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`itemtype`, `items_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::MODELS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

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
 * Issue #886 : rythme de renouvellement d'une preuve (une ligne par validité de preuve, issue #145),
 * créé si absent à chaque installation ou mise à jour, supprimé à la désinstallation.
 */
final class EvidenceRecurrenceTables implements FeatureTables
{
    public const RECURRENCES = 'glpi_plugin_grcmanager_evidencerecurrences';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::RECURRENCES)) {
            $DB->doQuery("CREATE TABLE `" . self::RECURRENCES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_evidences_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Validite de preuve',
                `period_unit` varchar(16) NOT NULL DEFAULT '' COMMENT 'day, week, month, year, month_end, quarter_end',
                `period_count` int NOT NULL DEFAULT 1 COMMENT 'Toutes les N unites',
                `last_collected` date NULL DEFAULT NULL COMMENT 'Derniere collecte saisie',
                `notified_due` date NULL DEFAULT NULL COMMENT 'Echeance deja rappelee',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `plugin_grcmanager_evidences_id` (`plugin_grcmanager_evidences_id`),
                KEY `period_unit` (`period_unit`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::RECURRENCES);
    }

    public static function pluginClasses(): array
    {
        return [\GlpiPlugin\Grcmanager\EvidenceRecurrence::class];
    }
}

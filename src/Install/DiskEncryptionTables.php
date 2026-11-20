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
 * Issue #847 : déclaration, par l'administrateur, de la sauvegarde de la clé de récupération du
 * chiffrement d'un ordinateur (case et date). Aucune clé n'est jamais lue ni stockée : la table ne
 * contient qu'un oui ou non, une date et l'auteur de la déclaration. Créée si absente (rejouable),
 * supprimée à la désinstallation.
 */
final class DiskEncryptionTables
{
    public const KEYS = 'glpi_plugin_grcmanager_diskencryptionkeys';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::KEYS)) {
            $DB->doQuery("CREATE TABLE `" . self::KEYS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `computers_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_backed_up` tinyint NOT NULL DEFAULT 0 COMMENT 'Declaration seulement, aucune cle stockee',
                `backup_date` date DEFAULT NULL COMMENT 'Date de sauvegarde declaree',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la declaration',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `computers_id` (`computers_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::KEYS);
    }
}

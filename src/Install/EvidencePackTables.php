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
 * Issue #868 : historique des dossiers de preuves générés pour l'auditeur (archive ZIP archivée
 * comme document restreint), créé si absent à chaque installation ou mise à jour, supprimé à la
 * désinstallation (le réglage de taille part avec le contexte de configuration du plugin).
 */
final class EvidencePackTables
{
    public const PACKS = 'glpi_plugin_grcmanager_evidencepacks';

    /** Classe source des documents restreints : nettoyée à la désinstallation. */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\EvidencePack::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::PACKS)) {
            $DB->doQuery("CREATE TABLE `" . self::PACKS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Perimetre du dossier',
                `documents_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Archive ZIP archivee',
                `sha256` varchar(64) NOT NULL DEFAULT '' COMMENT 'Empreinte de l archive',
                `size` bigint NOT NULL DEFAULT 0 COMMENT 'Taille de l archive en octets',
                `files_count` int NOT NULL DEFAULT 0 COMMENT 'Pieces incluses',
                `expired_count` int NOT NULL DEFAULT 0 COMMENT 'Preuves expirees signalees',
                `skipped_count` int NOT NULL DEFAULT 0 COMMENT 'Pieces ecartees par la limite de taille',
                `documents` longtext COMMENT 'Documents inclus (JSON), pour les droits de lecture',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la generation',
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `documents_id` (`documents_id`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::PACKS);
    }
}

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
 * Issue #771, lot 2 : prises de contact tracées avec les contacts officiels depuis un incident de
 * sécurité ou une violation de données (A.5.5, A.5.6). Table créée si absente à chaque installation
 * ou mise à jour (rejouable), supprimée à la désinstallation.
 *
 * Une ligne par prise de contact (une autorité peut recevoir une notification initiale puis un
 * complément) : le contact (partie intéressée du contexte), l'incident ou la violation, la décision
 * (prévenu ou non nécessaire, avec motif), la date, la référence du dossier et l'auteur. L'entité
 * est celle de l'incident ou de la violation.
 */
final class OfficialContactTables
{
    public const NOTIFICATIONS = 'glpi_plugin_grcmanager_officialcontactnotifications';

    /** Classes propres à ces tables (historique : nettoyé à la désinstallation). */
    public const CLASSES = [
        \GlpiPlugin\Grcmanager\OfficialContactNotification::class,
    ];

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if ($DB->tableExists(self::NOTIFICATIONS)) {
            return;
        }
        $DB->doQuery("CREATE TABLE `" . self::NOTIFICATIONS . "` (
            `id` int {$keySign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Entite de l incident ou de la violation',
            `itemtype` varchar(255) NOT NULL DEFAULT '' COMMENT 'Incident de securite ou violation de donnees',
            `items_id` int {$keySign} NOT NULL DEFAULT 0,
            `plugin_grcmanager_contextitems_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Contact officiel',
            `decision` varchar(16) NOT NULL DEFAULT '' COMMENT 'notified, not_required',
            `date_notified` timestamp NULL DEFAULT NULL COMMENT 'Date de la prise de contact ou de la decision',
            `reference` varchar(255) NOT NULL DEFAULT '' COMMENT 'Numero de dossier, accuse de reception',
            `comment` text COMMENT 'Canal, interlocuteur, motif si non necessaire',
            `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auteur de la trace',
            `plugin_grcmanager_crisiscommunications_id` int {$keySign} NOT NULL DEFAULT 0
                COMMENT 'Communication de crise d ou vient la trace (lot 3), 0 sinon',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_grcmanager_contextitems_id` (`plugin_grcmanager_contextitems_id`),
            KEY `entities_id` (`entities_id`),
            KEY `decision` (`decision`),
            KEY `date_notified` (`date_notified`),
            KEY `users_id` (`users_id`),
            KEY `plugin_grcmanager_crisiscommunications_id` (`plugin_grcmanager_crisiscommunications_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    /**
     * Lot 3 : lien vers la communication de crise d'où vient la prise de contact (table créée par
     * le lot 2 sans cette colonne).
     */
    public static function migrate(Migration $migration): void
    {
        global $DB;

        $field = 'plugin_grcmanager_crisiscommunications_id';
        if (!$DB->tableExists(self::NOTIFICATIONS) || $DB->fieldExists(self::NOTIFICATIONS, $field)) {
            return;
        }
        $migration->addField(self::NOTIFICATIONS, $field, 'fkey', ['after' => 'users_id']);
        $migration->addKey(self::NOTIFICATIONS, $field);
        $migration->migrationOneTable(self::NOTIFICATIONS);
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::NOTIFICATIONS);
    }
}

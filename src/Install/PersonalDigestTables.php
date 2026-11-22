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

use CronTask;
use GlpiPlugin\Grcmanager\PersonalDigest;
use Migration;

/**
 * Issue #877 : récapitulatif personnel par e-mail. Une ligne par personne : fréquence choisie
 * (vide : la valeur par défaut, chaque semaine), dernier envoi, dernier examen par la tâche.
 * L'entité est toujours la racine : la notification du plugin y est rattachée (récursive).
 *
 * Tâche automatique « personaldigest » enregistrée désactivée : rien ne part tant qu'un
 * administrateur ne l'a pas activée. Table, notification et modèle supprimés à la désinstallation.
 */
final class PersonalDigestTables implements FeatureTables
{
    public const DIGESTS = 'glpi_plugin_grcmanager_personaldigests';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::DIGESTS)) {
            $DB->doQuery("CREATE TABLE `" . self::DIGESTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Toujours la racine',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Personne destinataire',
                `frequency` varchar(16) NOT NULL DEFAULT '' COMMENT 'daily, weekly, never ; vide : weekly',
                `date_last_sent` timestamp NULL DEFAULT NULL COMMENT 'Dernier recapitulatif envoye',
                `items_count` int NOT NULL DEFAULT 0 COMMENT 'Elements du dernier recapitulatif',
                `date_last_check` date DEFAULT NULL COMMENT 'Dernier examen par la tache',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_id` (`users_id`),
                KEY `entities_id` (`entities_id`),
                KEY `date_last_check` (`date_last_check`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        CronTask::Register(
            PersonalDigest::class,
            PersonalDigest::CRON_NAME,
            HOUR_TIMESTAMP,
            [
                'comment' => 'Envoie a chacun le recapitulatif de ce qui l attend, a la frequence choisie',
                'mode'    => CronTask::MODE_EXTERNAL,
                'state'   => CronTask::STATE_DISABLE,
            ]
        );
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::DIGESTS);
        self::removeNotification();
    }

    /**
     * Supprime la notification « Votre récapitulatif GRC » et son modèle, par les classes du cœur
     * (leurs nettoyages suppriment cibles, liens et traductions).
     */
    public static function removeNotification(): void
    {
        global $DB;

        $itemtype = PersonalDigest::class;
        $notification = new \Notification();
        foreach ($DB->request(['FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $notification->delete(['id' => $row['id']], true);
        }
        $template = new \NotificationTemplate();
        foreach ($DB->request(['FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $template->delete(['id' => $row['id']], true);
        }
    }

    public static function pluginClasses(): array
    {
        return [
            PersonalDigest::class,
            \GlpiPlugin\Grcmanager\NotificationTargetPersonalDigest::class,
        ];
    }
}

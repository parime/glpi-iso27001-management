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
 * Issue #855 : domaines de l'organisation suivis pour la sécurité de la messagerie (SPF, DKIM, DMARC,
 * MTA-STS, TLS-RPT, DNSSEC), avec leurs échéances saisies et le résultat de la dernière lecture DNS.
 * Créée si absente (rejouable), supprimée à la désinstallation.
 */
final class MailDomainTables implements FeatureTables
{
    public const DOMAINS = 'glpi_plugin_grcmanager_maildomains';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::DOMAINS)) {
            $DB->doQuery("CREATE TABLE `" . self::DOMAINS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `name` varchar(253) NOT NULL DEFAULT '',
                `dkim_selectors` varchar(700) NOT NULL DEFAULT '' COMMENT 'Selecteurs DKIM declares',
                `domain_expiry` date NULL DEFAULT NULL COMMENT 'Echeance du domaine (bureau d enregistrement)',
                `certificate_expiry` date NULL DEFAULT NULL COMMENT 'Echeance du certificat de messagerie',
                `comment` varchar(255) NOT NULL DEFAULT '',
                `last_check` timestamp NULL DEFAULT NULL,
                `result` mediumtext NULL COMMENT 'Derniere lecture DNS (JSON)',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entity_name` (`entities_id`, `name`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::DOMAINS);
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function pluginClasses(): array
    {
        return [];
    }
}

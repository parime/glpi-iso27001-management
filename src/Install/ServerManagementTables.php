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
 * Issue #843 : contrôleurs de gestion des serveurs (iLO, iDRAC, XClarity, IPMI/BMC) et leurs comptes
 * locaux, lus par Redfish en lecture seule ou importés en CSV. Le mot de passe du compte de lecture
 * est chiffré par la clé de GLPI (GLPIKey) et jamais réaffiché. Une lecture en échec ne remplace
 * pas la précédente (seule l'erreur est notée). Créées si absentes (rejouable), supprimées à la
 * désinstallation, découvertes par l'installeur (FeatureTablesRegistry).
 */
final class ServerManagementTables implements FeatureTables
{
    public const CONTROLLERS = 'glpi_plugin_grcmanager_bmccontrollers';
    public const ACCOUNTS = 'glpi_plugin_grcmanager_bmcaccounts';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::CONTROLLERS)) {
            $DB->doQuery("CREATE TABLE `" . self::CONTROLLERS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `source` varchar(16) NOT NULL DEFAULT 'redfish' COMMENT 'redfish ou csv',
                `url` varchar(255) NOT NULL DEFAULT '' COMMENT 'https://hote[:port], seule adresse appelee',
                `username` varchar(255) NOT NULL DEFAULT '' COMMENT 'Compte local en lecture seule',
                `password` text COMMENT 'Chiffre par GLPIKey, jamais reaffiche',
                `allow_self_signed` tinyint NOT NULL DEFAULT 0 COMMENT 'Certificat accepte sans verification',
                `computers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Serveur choisi a la main (0 : numero de serie)',
                `ip_address` varchar(64) NOT NULL DEFAULT '' COMMENT 'Adresse IP au moment de la lecture',
                `vendor` varchar(64) NOT NULL DEFAULT '',
                `redfish_version` varchar(32) NOT NULL DEFAULT '',
                `manager_model` varchar(255) NOT NULL DEFAULT '',
                `firmware_version` varchar(255) NOT NULL DEFAULT '',
                `bios_version` varchar(255) NOT NULL DEFAULT '',
                `serial` varchar(255) NOT NULL DEFAULT '' COMMENT 'Numero de serie du serveur gere',
                `system_model` varchar(255) NOT NULL DEFAULT '',
                `manufacturer` varchar(255) NOT NULL DEFAULT '',
                `health` varchar(16) NOT NULL DEFAULT '' COMMENT 'OK, Warning, Critical ou vide',
                `power_state` varchar(16) NOT NULL DEFAULT '',
                `ipmi_lan` tinyint NOT NULL DEFAULT -1 COMMENT '-1 inconnu, 0 non, 1 oui',
                `telnet` tinyint NOT NULL DEFAULT -1,
                `snmp_v1v2` tinyint NOT NULL DEFAULT -1,
                `weak_tls` tinyint NOT NULL DEFAULT -1,
                `secure_boot` tinyint NOT NULL DEFAULT -1,
                `tls_verified` tinyint NOT NULL DEFAULT -1,
                `cert_self_signed` tinyint NOT NULL DEFAULT -1,
                `cert_expiry` date DEFAULT NULL,
                `accounts_read` tinyint NOT NULL DEFAULT 0,
                `date_read` timestamp NULL DEFAULT NULL COMMENT 'Derniere lecture reussie',
                `read_error` varchar(64) NOT NULL DEFAULT '' COMMENT 'Code de la derniere lecture en echec',
                `date_read_failed` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `computers_id` (`computers_id`),
                KEY `serial` (`serial`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::ACCOUNTS)) {
            $DB->doQuery("CREATE TABLE `" . self::ACCOUNTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_bmccontrollers_id` int {$keySign} NOT NULL DEFAULT 0,
                `username` varchar(255) NOT NULL DEFAULT '',
                `role` varchar(64) NOT NULL DEFAULT '',
                `is_enabled` tinyint NOT NULL DEFAULT 1,
                `is_locked` tinyint NOT NULL DEFAULT 0,
                `date_import` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_bmccontrollers_id` (`plugin_grcmanager_bmccontrollers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::CONTROLLERS);
        $migration->dropTable(self::ACCOUNTS);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

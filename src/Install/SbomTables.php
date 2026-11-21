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
 * Issue #869 : nomenclatures logicielles (SBOM) des applications développées ou fournies par
 * l'organisation (applications du cœur, comme le développement sécurisé), par version, et
 * déclarations VEX sur leurs vulnérabilités.
 *
 * - sboms : un import (CycloneDX ou SPDX en JSON) pour une application et une version ;
 * - sbomcomponents : composants lus dans le fichier (nom, version, purl, CPE, licences) ;
 * - sbomvulnerabilities : vulnérabilités citées par le fichier lui-même (CycloneDX) ;
 * - vexstatements : déclarations VEX (saisies ou importées), par application, version et vulnérabilité.
 */
final class SbomTables implements FeatureTables
{
    public const SBOMS = 'glpi_plugin_grcmanager_sboms';
    public const COMPONENTS = 'glpi_plugin_grcmanager_sbomcomponents';
    public const VULNERABILITIES = 'glpi_plugin_grcmanager_sbomvulnerabilities';
    public const VEX = 'glpi_plugin_grcmanager_vexstatements';

    public static function install(string $keySign, string $charset, string $collation): void
    {
        global $DB;

        if (!$DB->tableExists(self::SBOMS)) {
            $DB->doQuery("CREATE TABLE `" . self::SBOMS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `product_version` varchar(100) NOT NULL DEFAULT '',
                `format` varchar(16) NOT NULL DEFAULT '' COMMENT 'cyclonedx ou spdx',
                `spec_version` varchar(32) NOT NULL DEFAULT '',
                `document_name` varchar(255) NOT NULL DEFAULT '',
                `file_name` varchar(255) NOT NULL DEFAULT '',
                `components` int NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `appliances_id` (`appliances_id`),
                KEY `product_version` (`product_version`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::COMPONENTS)) {
            $DB->doQuery("CREATE TABLE `" . self::COMPONENTS . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_sboms_id` int {$keySign} NOT NULL DEFAULT 0,
                `bom_ref` varchar(255) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `version` varchar(100) NOT NULL DEFAULT '',
                `type` varchar(32) NOT NULL DEFAULT '',
                `purl` varchar(512) NOT NULL DEFAULT '',
                `cpe` varchar(512) NOT NULL DEFAULT '',
                `supplier` varchar(255) NOT NULL DEFAULT '',
                `licenses` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_sboms_id` (`plugin_grcmanager_sboms_id`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::VULNERABILITIES)) {
            $DB->doQuery("CREATE TABLE `" . self::VULNERABILITIES . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_sboms_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_sbomcomponents_id` int {$keySign} NOT NULL DEFAULT 0,
                `vulnerability_id` varchar(64) NOT NULL DEFAULT '',
                `cvss` decimal(3,1) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_sboms_id` (`plugin_grcmanager_sboms_id`),
                KEY `vulnerability_id` (`vulnerability_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }

        if (!$DB->tableExists(self::VEX)) {
            $DB->doQuery("CREATE TABLE `" . self::VEX . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `appliances_id` int {$keySign} NOT NULL DEFAULT 0,
                `product_version` varchar(100) NOT NULL DEFAULT '' COMMENT 'Vide : toutes les versions',
                `vulnerability_id` varchar(64) NOT NULL DEFAULT '',
                `component` varchar(512) NOT NULL DEFAULT '' COMMENT 'bom-ref, purl ou nom ; vide : le produit',
                `status` varchar(32) NOT NULL DEFAULT '' COMMENT 'not_affected, affected, fixed, under_investigation',
                `justification` varchar(64) NOT NULL DEFAULT '',
                `detail` text,
                `source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual, cyclonedx ou openvex',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `appliances_id` (`appliances_id`),
                KEY `vulnerability_id` (`vulnerability_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        }
    }

    public static function migrate(Migration $migration): void
    {
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::SBOMS);
        $migration->dropTable(self::COMPONENTS);
        $migration->dropTable(self::VULNERABILITIES);
        $migration->dropTable(self::VEX);
    }

    /** @return list<class-string> */
    public static function pluginClasses(): array
    {
        return [];
    }
}

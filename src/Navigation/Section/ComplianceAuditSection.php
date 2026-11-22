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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AdSecurityAudit;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\CanonicalProduct;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\ConfigurationCompliance;
use GlpiPlugin\Grcmanager\DiskEncryption;
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\MailDomainSecurity;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\NetworkFlow;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\PatchCompliance;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;

/**
 * Sécurité technique. Le parent ne demande que le droit du plugin : les vulnérabilités (qui exigent
 * aussi la lecture des logiciels) restent une sous-page parmi d'autres.
 */
final class ComplianceAuditSection extends AbstractSection
{
    public static function parent(): string
    {
        return ComplianceAudit::class;
    }

    public static function after(): ?string
    {
        return AccessReview::class;
    }

    public static function pages(): array
    {
        return [
            ParkVulnerability::class,
            // Pages de « Vulnérabilités du parc » sans classe propre, visibles avec les mêmes droits.
            Menu::MACHINE_RANKING => [
                'title'     => __('Most exposed machines', 'grcmanager'),
                'page'      => ParkVulnerability::getRankingURL(false),
                'icon'      => 'ti ti-device-desktop-exclamation',
                'rights_of' => ParkVulnerability::class,
            ],
            Menu::SOFTWARE_WATCH => [
                'title'     => __('Software to watch', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/softwarewatch.php',
                'icon'      => 'ti ti-radar',
                'rights_of' => ParkVulnerability::class,
            ],
            // Issue #779 : conformité des licences logicielles (A.5.32).
            SoftwareLicenseCompliance::class,
            // Issue #847 : chiffrement des disques du parc (A.8.24, A.8.1).
            DiskEncryption::class,
            // Issue #854 : retard des correctifs de sécurité (A.8.8, A.8.19).
            PatchCompliance::class,
            // Issue #835 : audit de sécurité de l'Active Directory (rapports PingCastle).
            AdSecurityAudit::class,
            // Issue #781 : conformité de configuration, résultats OpenSCAP importés (A.8.9).
            ConfigurationCompliance::class,
            // Issue #853 : couverture de la journalisation (A.8.15, A.8.16, A.8.17).
            LogCoverage::class,
            // Issue #836 : supervision lue dans Zabbix (A.8.16, A.8.6, A.5.30).
            ZabbixMonitoring::class,
            // Issue #842 : services exposés et certificats TLS lus dans Uptime Kuma (A.8.16, A.5.30, A.8.24).
            UptimeKumaMonitoring::class,
            // Issue #843 : contrôleurs de gestion des serveurs (A.8.2, A.8.9, A.8.22).
            ServerManagement::class,
            // Issue #838 : couverture des sauvegardes (A.8.13, A.5.30).
            BackupCoverage::class,
            // Issue #855 : sécurité de la messagerie et des domaines (A.5.14, A.8.21).
            MailDomainSecurity::class,
            CanonicalProduct::class,
            NetworkFlow::class,
            BreachExposure::class,
        ];
    }
}

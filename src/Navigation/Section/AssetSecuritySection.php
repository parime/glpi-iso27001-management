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

use GlpiPlugin\Grcmanager\AdSecurityAudit;
use GlpiPlugin\Grcmanager\AssetSecurity;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\CanonicalProduct;
use GlpiPlugin\Grcmanager\CodeSecurity;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\ConfigurationCompliance;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\DiskEncryption;
use GlpiPlugin\Grcmanager\HardwareModelEol;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\MailDomainSecurity;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\NetworkFlow;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\PatchCompliance;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\ScannerImport;
use GlpiPlugin\Grcmanager\SecureDevelopment;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;

/**
 * Issue #1039 : Parc > Sécurité du parc. Les écrans de sécurité technique (anciennement la rubrique
 * « Sécurité technique » du menu GRC), et la sécurité physique, le développement et le code (venus
 * de la rubrique des mesures), là où l'on cherche la sécurité d'un actif.
 *
 * Plus de 7 écrans : la barre montre la page d'accueil (cartes groupées) et un onglet replié par
 * groupe (option « tab_group »), qui ouvre une seconde barre avec les pages du groupe.
 */
final class AssetSecuritySection extends AbstractSection
{
    public static function sector(): string
    {
        return 'assets';
    }

    public static function parent(): string
    {
        return AssetSecurity::class;
    }

    public static function right(): string
    {
        return Installer::RIGHT_NAME;
    }

    /**
     * Groupes de la rubrique, dans l'ordre : libellé, icône et phrase d'explication (page d'accueil).
     *
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public static function groups(): array
    {
        return [
            'vulnerabilities' => [
                'label'       => __('Vulnerabilities', 'grcmanager'),
                'icon'        => 'ti ti-bug',
                'description' => __(
                    'Known flaws of the installed software and the most exposed machines.',
                    'grcmanager'
                ),
            ],
            'lifecycle'       => [
                'label'       => __('End of life and patches', 'grcmanager'),
                'icon'        => 'ti ti-calendar-x',
                'description' => __(
                    'Software and hardware that no longer receive fixes, late patches and licences.',
                    'grcmanager'
                ),
            ],
            'configuration'   => [
                'label'       => __('Hardening', 'grcmanager'),
                'icon'        => 'ti ti-adjustments-check',
                'description' => __(
                    'Automated checks of the inventory, secure configuration and disk encryption.',
                    'grcmanager'
                ),
            ],
            'monitoring'      => [
                'label'       => __('Monitoring', 'grcmanager'),
                'icon'        => 'ti ti-activity-heartbeat',
                'description' => __(
                    'Logs, supervision, exposed services and server management controllers.',
                    'grcmanager'
                ),
            ],
            'network'         => [
                'label'       => __('Backups and network', 'grcmanager'),
                'icon'        => 'ti ti-network',
                'description' => __('Backup coverage, mail and domain security, network flows.', 'grcmanager'),
            ],
            'sites'           => [
                'label'       => __('Sites and development', 'grcmanager'),
                'icon'        => 'ti ti-building-factory-2',
                'description' => __(
                    'Physical security of the sites, secure development and code security.',
                    'grcmanager'
                ),
            ],
        ];
    }

    public static function pages(): array
    {
        $groups = self::groups();
        $in = static fn (string $group, array $options = []): array => $options + [
            'tab_group'      => $groups[$group]['label'],
            'tab_group_icon' => $groups[$group]['icon'],
        ];

        return [
            ParkVulnerability::class => $in('vulnerabilities'),
            // Pages de « Vulnérabilités du parc » sans classe propre, visibles avec les mêmes droits.
            Menu::MACHINE_RANKING => $in('vulnerabilities', [
                'title'     => __('Most exposed machines', 'grcmanager'),
                'page'      => ParkVulnerability::getRankingURL(false),
                'icon'      => 'ti ti-device-desktop-exclamation',
                'rights_of' => ParkVulnerability::class,
            ]),
            CanonicalProduct::class => $in('vulnerabilities'),
            // Issue #543 : rapports de scanners de vulnérabilités importés (Greenbone / OpenVAS, A.8.8).
            ScannerImport::class => $in('vulnerabilities'),
            // Les comptes exposés dans des fuites de données sont dans « Personnes et sensibilisation »
            // (PeopleSection, lot 1).

            Menu::SOFTWARE_WATCH => $in('lifecycle', [
                'title'     => __('Software to watch', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/softwarewatch.php',
                'icon'      => 'ti ti-radar',
                'rights_of' => ParkVulnerability::class,
            ]),
            // Issue #1039 : fin de vie constructeur des modèles de matériel, saisie groupée.
            HardwareModelEol::class => $in('lifecycle'),
            // Issue #854 : retard des correctifs de sécurité (A.8.8, A.8.19).
            PatchCompliance::class => $in('lifecycle'),
            // Issue #779 : conformité des licences logicielles (A.5.32).
            SoftwareLicenseCompliance::class => $in('lifecycle'),

            ComplianceAudit::class => $in('configuration'),
            // Issue #781 : conformité de configuration, résultats OpenSCAP importés (A.8.9).
            ConfigurationCompliance::class => $in('configuration'),
            // Issue #847 : chiffrement des disques du parc (A.8.24, A.8.1).
            DiskEncryption::class => $in('configuration'),
            // L'audit de l'Active Directory (#835) est dans « Accès et comptes » (AccessReviewSection, #1047).

            // Issue #853 : couverture de la journalisation (A.8.15, A.8.16, A.8.17).
            LogCoverage::class => $in('monitoring'),
            // Issue #836 : supervision lue dans Zabbix (A.8.16, A.8.6, A.5.30).
            ZabbixMonitoring::class => $in('monitoring'),
            // Issue #842 : services exposés et certificats TLS lus dans Uptime Kuma.
            UptimeKumaMonitoring::class => $in('monitoring'),
            // Issue #843 : contrôleurs de gestion des serveurs (A.8.2, A.8.9, A.8.22).
            ServerManagement::class => $in('monitoring'),

            // Issue #838 : couverture des sauvegardes (A.8.13, A.5.30).
            BackupCoverage::class => $in('network'),
            // Issue #855 : sécurité de la messagerie et des domaines (A.5.14, A.8.21).
            MailDomainSecurity::class => $in('network'),
            NetworkFlow::class => $in('network'),

            // Issue #778 : sécurité physique des sites (A.7.1 à A.7.14), venue des mesures.
            PhysicalSecurity::class => $in('sites', ['from' => Control::class]),
            // Issue #777 : développement sécurisé (registre des applications, mises en production).
            SecureDevelopment::class => $in('sites', ['from' => Control::class]),
            // Issue #858 : sécurité du code lue dans GitHub et GitLab (A.8.28, A.8.29).
            CodeSecurity::class => $in('sites', ['from' => Control::class]),
        ];
    }
}

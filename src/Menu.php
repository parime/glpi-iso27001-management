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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;

/**
 * Simple ancre sans table ni CommonDBTM : ne sert qu'à porter le titre et l'icône du menu de
 * premier niveau dédié "GRC & Conformité" (voir setup.php). GLPI core (Html::generateMenuSession())
 * remplit `$menu[$category]['title']`/`['icon']` d'une clé de menu inconnue des sectors natifs avec
 * le premier élément de son tableau MENU_TOADD dont getMenuContent()/getIcon() fournissent une
 * valeur ; sans cette classe placée en tête du tableau, le titre du sector deviendrait celui du
 * premier écran fonctionnel (ex: "Risques") au lieu du nom du regroupement.
 */
class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return __('GRC & Compliance', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    /**
     * Pointe vers la page « Tableau de bord » du plugin (issue #247), comme le titre des menus
     * Parc et Assistance. Un lien `/front/central.php?dashboard=...` ne
     * convenait pas : `Glpi\Controller\CentralController` n'honore ce paramètre qu'avec `embed`.
     */
    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Dashboard::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /** Pseudo-éléments de « Vulnérabilités du parc » : pages sans classe propre. */
    public const SOFTWARE_WATCH = 'softwarewatch';
    public const MACHINE_RANKING = 'parkmachines';

    /**
     * Rubriques du menu qui regroupent plusieurs écrans d'un même travail : une seule entrée de
     * menu (le parent), les autres écrans en sous-pages (options GLPI : fil d'Ariane « Parent >
     * Sous-page ») et une barre d'onglets commune en tête de chaque page (showSection()).
     *
     * @return array<class-string, list<string>>
     */
    public static function sections(): array
    {
        return [
            // Pilotage.
            Dashboard::class => [
                Maturity::class,
                // Issue #603 : maturité par mesure (niveau actuel, niveau cible et écarts).
                MaturityCampaign::class,
                FrameworkAssessment::class, ComplianceEvent::class,
                // Issue #614 : contrôles de cohérence du SMSI avant un audit.
                ConsistencyCheck::class,
                // Issue #619 : préparation à la certification ISO 27001.
                CertificationReadiness::class,
            ],
            // Appréciation et traitement des risques.
            Risk::class => [
                SupplierRisk::class, ImpactAnalysis::class,
                CausalLink::class, Waiver::class,
                RiskAppetite::class, RiskIndicator::class,
                // Issue #376 : études EBIOS Risk Manager.
                EbiosStudy::class,
            ],
            // Conformité : SoA, référentiels, exigences du SMSI, obligations, politiques, preuves.
            Control::class => [
                // Issue #867 : mesures bloquées (ce qui empêche chaque mesure d'être conforme).
                ControlBlocker::class,
                // Issue #873 : cadrage guidé de la SoA par questions (applicabilité et justifications).
                SoaScoping::class,
                Referentials::class, CustomFramework::class, IsmsRequirement::class,
                // Issue #608 : contexte de l'organisation (enjeux, parties intéressées).
                ContextItem::class,
                // Issue #771 : registre des contacts officiels (A.5.5, A.5.6).
                OfficialContact::class,
                // Issue #612 : matrice des responsabilités du SMSI (article 5.3, mesure A.5.3).
                ResponsibilityActivity::class,
                ComplianceObligation::class, ProcessingActivity::class, DataSubjectRequest::class,
                // Issue #620 : registre des violations de données personnelles.
                DataBreach::class, Policy::class,
                // Issue #879 : mesures couvertes par les textes de la bibliothèque des politiques.
                PolicyCoverage::class,
                // Issue #545 : modèles de politiques prêts à adapter, importés en brouillon.
                PolicyTemplate::class,
                // Issue #775 : registre des procédures d'exploitation et couverture (A.5.37).
                OperatingProcedure::class,
                // Issue #778 : sécurité physique des sites (A.7.1 à A.7.14), comparaison entre sites.
                PhysicalSecurity::class,
                // Issue #777 : développement sécurisé (registre des applications, mises en production).
                SecureDevelopment::class,
                Evidence::class,
                // Issue #868 : dossier de preuves exportable pour l'auditeur (archive ZIP par périmètre).
                EvidencePack::class,
                CoverageRule::class, ControlTest::class,
                // Issue #540 : registre d'informations DORA (référentiel DORA activé).
                DoraRegister::class,
            ],
            // Contrôle et amélioration continue (ISO 27001 chapitres 9 et 10).
            Audit::class => [
                // Issue #773 : programme d'audit pluriannuel (ISO 19011).
                AuditProgramme::class,
                Nonconformity::class,
                // Issue #870 : rapport du stock d'écarts (clauses 10.1 et 10.2).
                GapStockReport::class,
                // Issue #772 : constats des audits de certification et état de leur levée.
                CertificationFinding::class,
                // Issue #772, lot 2 : plan d'audit de l'organisme (échantillonnage sur le cycle).
                CertificationSampling::class,
                Objective::class,
                ManagementReview::class,
            ],
            // Opérations récurrentes : accès, revues, personnes, continuité.
            AccessReview::class => [
                AccountHygiene::class,
                // Issue #546 : fiche de conformité sécurité de chaque collaborateur.
                PersonnelCompliance::class,
                // Issue #852 : couverture de l'authentification multifacteur par compte (A.8.5).
                MfaCoverage::class,
                // Issue #846 : droits d'accès aux partages de fichiers Windows (A.5.15, A.5.18, A.8.3).
                ShareAccess::class,
                PeriodicReview::class,
                Training::class,
                // Issue #604 : simulations d'hameçonnage (preuve de sensibilisation, 7.3 et A.6.3).
                PhishingCampaign::class,
                // Issue #774 : compétences requises par fonction, acquises et écarts (article 7.2).
                IsmsFunction::class,
                CrisisExercise::class,
                // Issue #377 : processus métier et analyses d'impact sur l'activité (BIA).
                BusinessProcess::class,
                // Issue #776 : scénarios de crise sécurité de l'information du PCA (A.5.29).
                CrisisScenario::class,
            ],
            // Sécurité technique. Le parent ne demande que le droit du plugin : les vulnérabilités
            // (qui exigent aussi la lecture des logiciels) restent une sous-page parmi d'autres.
            ComplianceAudit::class => [
                ParkVulnerability::class, self::MACHINE_RANKING, self::SOFTWARE_WATCH,
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
                // Issue #843 : contrôleurs de gestion des serveurs (A.8.2, A.8.9, A.8.22).
                ServerManagement::class,
                // Issue #838 : couverture des sauvegardes (A.8.13, A.5.30).
                BackupCoverage::class,
                CanonicalProduct::class, NetworkFlow::class, BreachExposure::class,
            ],
        ];
    }

    /**
     * Entrée de menu d'une sous-page, telle que l'écran la déclare (droits compris), ou null si
     * l'utilisateur ne peut pas la voir.
     *
     * @return array<string, mixed>|null
     */
    private static function subPageMenu(string $child): ?array
    {
        if ($child === self::SOFTWARE_WATCH) {
            return ParkVulnerability::canView() ? [
                'title' => __('Software to watch', 'grcmanager'),
                'page'  => '/plugins/grcmanager/front/softwarewatch.php',
                'icon'  => 'ti ti-radar',
            ] : null;
        }
        if ($child === self::MACHINE_RANKING) {
            return ParkVulnerability::canView() ? [
                'title' => __('Most exposed machines', 'grcmanager'),
                'page'  => ParkVulnerability::getRankingURL(false),
                'icon'  => 'ti ti-device-desktop-exclamation',
            ] : null;
        }
        $menu = $child::getMenuContent();

        return is_array($menu) && $menu !== [] ? $menu : null;
    }

    /**
     * Ajoute les sous-pages de la rubrique $parent à son entrée de menu. Si l'utilisateur ne peut
     * pas voir le parent, sa première sous-page autorisée prend sa place : un droit propre à une
     * sous-page (RGPD pour un DPO, continuité) suffit à la trouver dans le menu, sans donner le droit
     * général. Chaque sous-page déclare elle-même ses droits (subPageMenu()) : rien n'est élargi.
     *
     * @param array<string, mixed>|false $menu
     * @return array<string, mixed>|false
     */
    public static function withSubPages(string $parent, $menu)
    {
        $parentVisible = is_array($menu) && $menu !== [];
        foreach (self::sections()[$parent] ?? [] as $child) {
            $childMenu = self::subPageMenu($child);
            if ($childMenu === null) {
                continue;
            }
            if (!is_array($menu) || $menu === []) {
                $menu = $childMenu;
            }
            $menu['options'][$child] = $childMenu;
        }
        if (!$parentVisible && is_array($menu) && $menu !== []) {
            $menu['parent_hidden'] = true;
        }

        return $menu;
    }

    /**
     * Barre d'onglets de la rubrique de $parent, $active étant la page affichée (le parent
     * lui-même ou l'une de ses sous-pages). Rien si l'utilisateur ne voit qu'une seule page.
     */
    public static function showSection(string $parent, string $active): void
    {
        global $CFG_GLPI;

        $parentMenu = $parent::getMenuContent();
        if (!is_array($parentMenu) || $parentMenu === []) {
            return;
        }
        // Parent invisible : son entrée de menu n'est que sa première sous-page, déjà listée.
        $pages = empty($parentMenu['parent_hidden']) ? [$parent => $parentMenu] : [];
        foreach (self::sections()[$parent] ?? [] as $child) {
            $childMenu = self::subPageMenu($child);
            if ($childMenu !== null) {
                $pages[$child] = $childMenu;
            }
        }
        if (count($pages) < 2) {
            return;
        }
        $tabs = [];
        foreach ($pages as $key => $page) {
            $tabs[] = [
                // Le parent peut porter, dans sa barre, un nom de page distinct du nom de la rubrique.
                'label'  => (string) ($key === $parent ? ($page['section_label'] ?? $page['title']) : $page['title']),
                'icon'   => (string) ($page['icon'] ?? ''),
                'url'    => $CFG_GLPI['root_doc'] . $page['page'],
                'active' => $key === $active,
            ];
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/_section_nav.html.twig',
            ['tabs' => $tabs]
        );
    }
}

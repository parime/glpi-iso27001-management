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

/**
 * Simple ancre sans table ni CommonDBTM : ne sert qu'à porter le titre et l'icône du menu de
 * premier niveau dédié "GRC & Conformité" (voir setup.php). GLPI core (Html::generateMenuSession())
 * remplit `$menu[$category]['title']`/`['icon']` d'une clé de menu inconnue des sectors natifs avec
 * le premier élément de son tableau MENU_TOADD dont getMenuContent()/getIcon() fournissent une
 * valeur ; sans cette classe placée en tête du tableau, le titre du sector deviendrait celui du
 * premier écran fonctionnel (ex: "Risques") au lieu du nom du regroupement.
 */
class PluginGrcmanagerMenu extends CommonGLPI
{
    public static function getMenuName()
    {
        return __('GRC & Conformité', 'grcmanager');
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
            'page'  => PluginGrcmanagerDashboard::getSearchURL(false),
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
            PluginGrcmanagerDashboard::class         => [PluginGrcmanagerMaturity::class],
            PluginGrcmanagerImpactAnalysis::class    => [PluginGrcmanagerCausalLink::class],
            PluginGrcmanagerControl::class           => [PluginGrcmanagerReferentials::class],
            PluginGrcmanagerEvidence::class          => [PluginGrcmanagerCoverageRule::class],
            PluginGrcmanagerAccessReview::class      => [PluginGrcmanagerAccountHygiene::class],
            PluginGrcmanagerParkVulnerability::class => [
                self::MACHINE_RANKING, self::SOFTWARE_WATCH, PluginGrcmanagerCanonicalProduct::class,
            ],
        ];
    }

    /** Écran rattaché à une rubrique : il n'a pas d'entrée de menu propre (setup.php). */
    public static function isSubPage(string $class): bool
    {
        return in_array($class, array_merge(...array_values(self::sections())), true);
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
            return PluginGrcmanagerParkVulnerability::canView() ? [
                'title' => __('Logiciels à surveiller', 'grcmanager'),
                'page'  => '/plugins/grcmanager/front/softwarewatch.php',
                'icon'  => 'ti ti-radar',
            ] : null;
        }
        if ($child === self::MACHINE_RANKING) {
            return PluginGrcmanagerParkVulnerability::canView() ? [
                'title' => __('Machines les plus exposées', 'grcmanager'),
                'page'  => PluginGrcmanagerParkVulnerability::getRankingURL(false),
                'icon'  => 'ti ti-device-desktop-exclamation',
            ] : null;
        }
        $menu = $child::getMenuContent();

        return is_array($menu) && $menu !== [] ? $menu : null;
    }

    /**
     * Ajoute les sous-pages de la rubrique $parent à son entrée de menu.
     *
     * @param array<string, mixed>|false $menu
     * @return array<string, mixed>|false
     */
    public static function withSubPages(string $parent, $menu)
    {
        if (!is_array($menu) || $menu === []) {
            return $menu;
        }
        foreach (self::sections()[$parent] ?? [] as $child) {
            $childMenu = self::subPageMenu($child);
            if ($childMenu !== null) {
                $menu['options'][$child] = $childMenu;
            }
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
        $pages = [$parent => $parentMenu];
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
                // Le parent peut porter un nom de rubrique distinct du nom de sa page.
                'label'  => (string) ($page['section_label'] ?? $page['title']),
                'icon'   => (string) ($page['icon'] ?? ''),
                'url'    => $CFG_GLPI['root_doc'] . $page['page'],
                'active' => $key === $active,
            ];
        }
        Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@grcmanager/_section_nav.html.twig',
            ['tabs' => $tabs]
        );
    }
}

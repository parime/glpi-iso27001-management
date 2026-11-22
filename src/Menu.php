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
use GlpiPlugin\Grcmanager\Navigation\Sections;
use Html;

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

    /** Pseudo-éléments de « Vulnérabilités du parc » : pages sans classe propre (ComplianceAuditSection). */
    public const SOFTWARE_WATCH = 'softwarewatch';
    public const MACHINE_RANKING = 'parkmachines';

    /**
     * Rubriques du menu qui regroupent plusieurs écrans d'un même travail : une seule entrée de
     * menu (le parent), les autres écrans en sous-pages (options GLPI : fil d'Ariane « Parent >
     * Sous-page ») et une barre d'onglets commune en tête de chaque page (showSection()).
     *
     * Issue #1037 : chaque rubrique est déclarée dans son fichier src/Navigation/Section/<X>Section.php
     * (voir Navigation\Sections) ; cette liste n'est plus écrite à la main.
     *
     * @return array<string, list<string>>
     */
    public static function sections(): array
    {
        // Dans l'ordre du menu : secteur par secteur, puis la place demandée par chaque rubrique.
        $sections = [];
        foreach (Sections::SECTORS as $sector) {
            foreach (Sections::ordered($sector, false) as $parent) {
                $pages = array_keys(Sections::pages($parent));
                if ($pages !== []) {
                    $sections[$parent] = $pages;
                }
            }
        }

        return $sections;
    }

    /**
     * Entrée de menu d'une sous-page, telle que l'écran la déclare (droits compris), ou null si
     * l'utilisateur ne peut pas la voir. Une page sans classe propre décrit son entrée dans sa
     * rubrique (title, page, icon) et emprunte les droits d'une classe (rights_of).
     *
     * @return array<string, mixed>|null
     */
    public static function pageMenu(string $child): ?array
    {
        $options = Sections::page($child) ?? [];
        if (!Sections::allowed($options)) {
            return null;
        }
        if (isset($options['page'])) {
            $rightsOf = (string) ($options['rights_of'] ?? '');
            if ($rightsOf === '' || !class_exists($rightsOf) || !(bool) $rightsOf::canView()) {
                return null;
            }

            return [
                'title' => (string) ($options['title'] ?? ''),
                'page'  => (string) $options['page'],
                'icon'  => (string) ($options['icon'] ?? ''),
            ];
        }
        // Issue #1037 : une page que le profil ne peut pas ouvrir n'est jamais affichée, même si son
        // entrée de menu ne vérifie pas elle-même le droit.
        if (!self::profileCanOpen($child)) {
            return null;
        }
        $menu = $child::getMenuContent();

        return is_array($menu) && $menu !== [] ? $menu : null;
    }

    /**
     * Le profil peut-il ouvrir la page de $class ? Décidé par son canView() quand la classe déclare
     * un droit ou son propre canView() ; sinon (page sans droit propre, comme les référentiels),
     * son entrée de menu décide seule.
     */
    private static function profileCanOpen(string $class): bool
    {
        if (!method_exists($class, 'canView')) {
            return true;
        }
        $declaring = (new \ReflectionMethod($class, 'canView'))->getDeclaringClass()->getName();
        $own = str_starts_with($declaring, __NAMESPACE__ . '\\') || str_starts_with($declaring, 'PluginGrcmanager');
        $rightname = property_exists($class, 'rightname') ? (string) $class::$rightname : '';
        if (!$own && $rightname === '') {
            return true;
        }

        return (bool) $class::canView();
    }

    /**
     * Ajoute les sous-pages de la rubrique $parent à son entrée de menu. Si l'utilisateur ne peut
     * pas voir le parent, sa première sous-page autorisée prend sa place : un droit propre à une
     * sous-page (RGPD pour un DPO, continuité) suffit à la trouver dans le menu, sans donner le droit
     * général. Chaque sous-page déclare elle-même ses droits (pageMenu()) : rien n'est élargi.
     *
     * @param array<string, mixed>|false $menu
     * @return array<string, mixed>|false
     */
    public static function withSubPages(string $parent, $menu)
    {
        // Issue #1037 : parent interdit au profil (droit vérifié ici, l'entrée ne le fait pas toujours).
        if (!self::profileCanOpen($parent)) {
            $menu = false;
        }
        $parentVisible = is_array($menu) && $menu !== [];
        foreach (array_keys(Sections::pages($parent)) as $child) {
            $childMenu = self::pageMenu($child);
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

        return self::withSectionHome($parent, $menu);
    }

    /**
     * Issue #1038 : nom parlant de la rubrique (title()) et page d'accueil (hub()) : l'entrée de
     * menu, donc le fil d'Ariane, mène à front/section.php ; l'adresse propre de la page parente
     * reste celle de son onglet (section_page).
     *
     * @param array<string, mixed>|false $menu
     * @return array<string, mixed>|false
     */
    private static function withSectionHome(string $parent, $menu)
    {
        $section = Sections::sectionOf($parent);
        if ($section === null || !is_array($menu) || $menu === []) {
            return $menu;
        }
        if ($section::title() !== '') {
            if (empty($menu['parent_hidden'])) {
                $menu['section_label'] = $menu['section_label'] ?? $menu['title'];
            }
            $menu['title'] = $section::title();
        }
        if ($section::hub()) {
            $menu['section_page'] = $menu['page'];
            $menu['page'] = Sections::hubUrl($section);
        }

        return $menu;
    }

    /**
     * En-tête GLPI d'une page du plugin : le secteur et la rubrique viennent de la déclaration de la
     * rubrique (src/Navigation/Section/), le fil d'Ariane est « Rubrique > Sous-page ». $page est
     * l'entrée de menu d'une rubrique (en-tête sans sous-page) ou l'une de ses sous-pages. Déplacer
     * un écran ne touche ainsi que la déclaration de sa rubrique, pas sa page front/.
     */
    public static function header(string $page, string $title, string $url = ''): void
    {
        if ($url === '') {
            $url = (string) ($_SERVER['PHP_SELF'] ?? '');
        }
        Html::header($title, $url, ...self::sectorPath($page));
    }

    /**
     * Secteur, rubrique et, pour une sous-page, la page elle-même : les arguments de menu de
     * Html::header() et de displayFullPageForItem() (clé « central »). Une page inconnue du registre
     * reste dans le menu du plugin.
     *
     * @return list<string>
     */
    public static function sectorPath(string $page): array
    {
        $place = Sections::locate($page);
        if ($place === null) {
            return [Sections::GRC, $page];
        }

        return $place['parent'] === $page
            ? [$place['sector'], $page]
            : [$place['sector'], $place['parent'], $page];
    }

    /**
     * Barre d'onglets de la rubrique de la page $active (l'entrée de menu de la rubrique ou l'une de
     * ses sous-pages). Rien si l'utilisateur ne voit qu'une seule page. L'ancienne forme
     * showSection($parent, $active) reste acceptée : la rubrique déclarée de $active l'emporte.
     */
    public static function showSection(string $active, ?string $legacyActive = null): void
    {
        global $CFG_GLPI;

        $fallback = $active;
        if ($legacyActive !== null) {
            $active = $legacyActive;
        }
        $parent = Sections::locate($active)['parent'] ?? $fallback;
        if (!class_exists($parent)) {
            return;
        }

        $parentMenu = $parent::getMenuContent();
        if (!is_array($parentMenu) || $parentMenu === []) {
            return;
        }
        $declared = Sections::pages($parent);
        // Parent invisible : son entrée de menu n'est que sa première sous-page, déjà listée.
        $pages = empty($parentMenu['parent_hidden']) ? [$parent => $parentMenu] : [];
        foreach (array_keys($declared) as $child) {
            $childMenu = self::pageMenu($child);
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
                // Page parente d'une rubrique avec page d'accueil : son adresse propre (#1038).
                'url'    => $CFG_GLPI['root_doc'] . ($key === $parent ? ($page['section_page'] ?? $page['page']) : $page['page']),
                'active' => $key === $active,
                // Groupe d'onglets facultatif de la sous-page (option « group » de sa rubrique).
                'group'  => (string) ($declared[$key]['group'] ?? ''),
            ];
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/_section_nav.html.twig',
            ['tabs' => $tabs]
        );
    }
}

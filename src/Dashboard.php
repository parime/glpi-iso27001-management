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
use GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use Session;

/**
 * Issue #247 : entrée « Tableau de bord » du menu GRC & Conformité, comme celles des menus Parc et
 * Assistance de GLPI. La page affiche le tableau de bord natif « Vue d'ensemble ISMS » (grille,
 * cartes, édition et partage de GLPI) ; aucune donnée propre.
 */
class Dashboard extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Dashboard', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-dashboard';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/dashboard.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function getMenuContent()
    {
        // Rubrique « Tableau de bord » : avec le score de maturité.
        return Menu::withSubPages(self::class, [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ]);
    }

    public static function getDashboardKey(): string
    {
        return DefaultDashboardService::KEY;
    }

    /**
     * Issue #143 : tableaux de bord proposés sur la page. La vue des incidents n'apparaît que si
     * l'utilisateur peut voir leurs cartes (droit, groupe sécurité, module et cartes actifs).
     *
     * @return array<string, array{key: string, label: string, icon: string}>
     */
    public static function getViews(): array
    {
        $views = [
            'isms' => [
                'key'   => DefaultDashboardService::KEY,
                'label' => __('ISMS overview', 'grcmanager'),
                'icon'  => self::getIcon(),
            ],
        ];
        if (SecuredDashboardCards::canShow('open')) {
            $views['incidents'] = [
                'key'   => DefaultDashboardService::INCIDENTS_KEY,
                'label' => \PluginGrcmanagerSecurityIncident::getTypeName(Session::getPluralNumber()),
                'icon'  => \PluginGrcmanagerSecurityIncident::getIcon(),
            ];
        }

        return $views;
    }
}

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

use GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService;

/**
 * Issue #247 : entrée « Tableau de bord » du menu GRC & Conformité, comme celles des menus Parc et
 * Assistance de GLPI. La page affiche le tableau de bord natif « Vue d'ensemble ISMS » (grille,
 * cartes, édition et partage de GLPI) ; aucune donnée propre.
 */
class PluginGrcmanagerDashboard extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Tableau de bord', 'grcmanager');
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
        return PluginGrcmanagerMenu::withSubPages(self::class, [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ]);
    }

    public static function getDashboardKey(): string
    {
        return DefaultDashboardService::KEY;
    }
}

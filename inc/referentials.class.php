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
 * Simple ancre sans table ni CommonDBTM, même convention que PluginGrcmanagerMenu (voir son propre
 * docblock) : ne sert qu'à donner une entrée de menu propre à front/referentiels.php (ROADMAP.md
 * "Version 2.2", bibliothèque de contrôles étendue) — un écran purement consultatif, sans
 * CommonDBTM ni table qui lui soit propre.
 */
class PluginGrcmanagerReferentials extends CommonGLPI
{
    public static function getMenuName()
    {
        return __('Référentiels', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-books';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(PluginGrcmanagerControl::$rightname, READ)) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/grcmanager/front/referentiels.php',
            'icon'  => self::getIcon(),
        ];
    }
}

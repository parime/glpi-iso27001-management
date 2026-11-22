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

namespace GlpiPlugin\Grcmanager\Config;

use Session;

/**
 * Issue #1044 : réglage déplacé d'une page d'exploitation vers un onglet de la configuration.
 * L'ancien envoi du formulaire reste accepté pendant une version : il est enregistré comme avant,
 * puis la personne est renvoyée sur l'onglet avec ce message.
 */
final class MovedSetting
{
    public static function notice(string $tabLabel): void
    {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('This setting is now in the plugin configuration, tab %s.', 'grcmanager'),
            $tabLabel
        )), false, INFO);
    }
}

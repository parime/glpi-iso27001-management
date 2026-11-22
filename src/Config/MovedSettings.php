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
 * Issue #1042 : réglages de connecteur déplacés d'une page d'exploitation vers l'onglet
 * « Connecteurs » de la configuration. Pendant une version, l'ancien envoi de la page reste accepté :
 * il est enregistré, puis la personne est menée au bloc de la configuration avec ce message.
 */
final class MovedSettings
{
    public static function notice(): void
    {
        Session::addMessageAfterRedirect(htmlescape(__(
            'These settings are now in Setup > Plugins > GRC Manager, Connectors tab.',
            'grcmanager'
        )), false, INFO);
    }
}

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

use Html;
use Session;

/**
 * Issue #1042 : réglages de connecteur déplacés d'une page d'exploitation vers l'onglet
 * « Connecteurs » de la configuration. Pendant une version, l'ancien envoi de la page reste accepté :
 * il est enregistré, puis la personne est menée au bloc de la configuration avec ce message.
 */
final class MovedSettings
{
    /** @param string $tabLabel libellé traduit de l'onglet de destination */
    public static function notice(string $tabLabel): void
    {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('These settings are now in Setup > Plugins > GRC Manager, %s tab.', 'grcmanager'),
            $tabLabel
        )), false, INFO);
    }

    /**
     * Retour au bloc de la configuration après un envoi (Html::redirect() termine la requête ; la
     * méthode est séparée pour que handle() garde son « return true » sous GLPI 11 et 12).
     */
    public static function redirect(string $anchor): void
    {
        Html::redirect(ConfigTabs::anchorUrl($anchor));
    }
}

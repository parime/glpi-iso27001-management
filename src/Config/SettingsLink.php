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
 * Issue #1045 : lien « Régler » d'une page d'exploitation vers le bloc de la configuration du
 * plugin qui porte ses réglages, et redirection d'un ancien envoi du formulaire de la page.
 * Gabarit : templates/config/_settings_link.html.twig (variable « settings_link »).
 */
final class SettingsLink
{
    /**
     * Données du lien vers le bloc d'identifiant $anchor : adresse et chemin lisible
     * (Configuration > Plugins > GRC Manager > <onglet>).
     *
     * @return array{url: string, tab: string, path: string, anchor: string}
     */
    public static function data(string $anchor): array
    {
        $label = self::tabLabel(ConfigTabs::tabOfAnchor($anchor));

        return [
            'url'    => ConfigTabs::anchorUrl($anchor),
            'tab'    => $label,
            'path'   => implode(' > ', array_filter([__('Setup'), _n('Plugin', 'Plugins', 2), 'GRC Manager', $label])),
            'anchor' => $anchor,
        ];
    }

    /**
     * Ancien envoi du formulaire de réglages d'une page d'exploitation (accepté pendant une
     * version) : message qui indique le nouvel emplacement, puis ouverture du bloc.
     */
    public static function redirectMoved(string $anchor): void
    {
        $link = self::data($anchor);
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Settings saved. They are now in %s.', 'grcmanager'),
            $link['path']
        )));
        Html::redirect($link['url']);
    }

    private static function tabLabel(string $key): string
    {
        foreach (ConfigTabs::tabClasses() as $tab) {
            if ($tab::key() === $key) {
                return $tab::label();
            }
        }

        return '';
    }
}

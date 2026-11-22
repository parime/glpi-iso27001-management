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

namespace GlpiPlugin\Grcmanager\Feature;

/**
 * Issue #1039 : bloc sorti de la page de configuration vers un écran d'exploitation. Son ancien lien
 * `config.php#<identifiant>` mène à cet écran (ConfigTabs::anchorMap() et anchorUrl()).
 */
interface MovedConfigAnchorsProvider
{
    /**
     * Anciens identifiants de bloc et adresse de leur écran, depuis la racine de GLPI
     * (`'grc-hardware-eol-config' => '/plugins/grcmanager/front/hardwaremodeleol.php'`).
     *
     * @return array<string, string>
     */
    public static function movedConfigAnchors(): array;
}

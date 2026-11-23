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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;

/**
 * Issue #539 : « Mon espace GRC », tout ce qui attend l'utilisateur connecté. Le nombre d'éléments
 * en attente est ajouté au titre par plugin_grcmanager_redefine_menus() (le menu est gardé en session).
 */
final class MySpaceSection extends AbstractSection
{
    public static function parent(): string
    {
        return MySpace::class;
    }

    public static function after(): string
    {
        return Dashboard::class;
    }

    /** Ouvert à tout utilisateur connecté ; dans le menu avec un droit de lecture du plugin. */
    public static function right(): string
    {
        return Dashboard::RIGHTNAME;
    }
}

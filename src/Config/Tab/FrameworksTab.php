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

namespace GlpiPlugin\Grcmanager\Config\Tab;

use GlpiPlugin\Grcmanager\Config\ConfigTab;

/** Onglet « Référentiels » de la configuration du plugin (issue #1036). */
final class FrameworksTab extends ConfigTab
{
    public static function key(): string
    {
        return 'frameworks';
    }

    public static function label(): string
    {
        return __('Frameworks', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-books';
    }

    public static function order(): int
    {
        return 20;
    }
}

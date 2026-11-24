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
 * Issue #1082 : point d'extension de la cartographie du SI. Une fonctionnalité qui connaît d'autres
 * liens (données réseau importées d'un laboratoire, flux applicatifs...) déclare ses sources ici,
 * sans modifier GlpiPlugin\Grcmanager\Cartography.
 */
interface CartographySourcesProvider
{
    /**
     * @return list<class-string<\GlpiPlugin\Grcmanager\Services\Cartography\EdgeSource>>
     */
    public static function cartographySources(): array;
}

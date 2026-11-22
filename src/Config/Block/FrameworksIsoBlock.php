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

namespace GlpiPlugin\Grcmanager\Config\Block;

use GlpiPlugin\Grcmanager\Config\FrameworkFamilyBlock;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/** Issue #1053 : normes ISO (systèmes de management et guides de la famille 27000). */
final class FrameworksIsoBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 20;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_iso.html.twig';
    }

    public static function label(): string
    {
        return __('ISO standards', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-certificate';
    }

    public static function members(): array
    {
        return [
            FrameworkSelection::ISO27001,
            FrameworkSelection::ISO27701,
            FrameworkSelection::ISO22301,
            FrameworkSelection::ISO42001,
            FrameworkSelection::ISO27090,
        ];
    }
}

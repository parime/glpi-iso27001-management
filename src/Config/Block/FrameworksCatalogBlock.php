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

/** Issue #1053 : catalogues de mesures reliés à l'Annexe A. */
final class FrameworksCatalogBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 60;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_catalog.html.twig';
    }

    public static function label(): string
    {
        return __('Control catalogs', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-list-details';
    }

    public static function members(): array
    {
        return [
            FrameworkSelection::NIST_CSF,
            FrameworkSelection::CIS_CONTROLS,
            FrameworkSelection::SCF,
        ];
    }
}

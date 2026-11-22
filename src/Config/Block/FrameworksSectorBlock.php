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

/** Issue #1053 : référentiels sectoriels (paiement, santé, rapports d'assurance, services de confiance). */
final class FrameworksSectorBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 50;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_sector.html.twig';
    }

    public static function label(): string
    {
        return __('Sector frameworks', 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-building-factory-2';
    }

    public static function members(): array
    {
        return [
            FrameworkSelection::PCI_DSS,
            FrameworkSelection::HDS,
            FrameworkSelection::SOC2,
            FrameworkSelection::ETSI_319401,
        ];
    }
}

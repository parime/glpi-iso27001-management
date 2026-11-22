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

use GlpiPlugin\Grcmanager\Config\ConfigBlock;

/** Issue #865, lot 2 : lien sécurisé de réponse du fournisseur. */
final class SupplierPortalBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'general';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_supplier_portal.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-supplier-portal-config'];
    }

    public static function data(): array
    {
        return [
            'supplier_portal' => \GlpiPlugin\Grcmanager\SupplierEvaluationLink::configData(),
        ];
    }
}

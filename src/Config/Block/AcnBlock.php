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

/** Issue #703 : catégorie NIS2 italienne des entités (registre ACN). */
final class AcnBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'frameworks';
    }

    public static function order(): int
    {
        return 90;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_acn.html.twig';
    }

    public static function anchors(): array
    {
        return ['grcmanager-acn'];
    }

    public static function data(): array
    {
        return [
            'acn_enabled'      => \GlpiPlugin\Grcmanager\AcnRequirement::isFrameworkEnabled(),
            'acn_entities'     => \GlpiPlugin\Grcmanager\AcnRequirement::isFrameworkEnabled()
                ? \GlpiPlugin\Grcmanager\AcnRequirement::configRows() : [],
            'acn_categories'   => \GlpiPlugin\Grcmanager\AcnRequirement::getCategories(),
            'acn_register_url' => \GlpiPlugin\Grcmanager\AcnRequirement::getSearchURL(),
        ];
    }
}

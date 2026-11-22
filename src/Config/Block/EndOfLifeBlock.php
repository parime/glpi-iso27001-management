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

/** Issue #704 : fin de vie des logiciels (endoflife.date). */
final class EndOfLifeBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 30;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_endoflife.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-endoflife-config'];
    }

    public static function data(): array
    {
        return [
            'endoflife' => \GlpiPlugin\Grcmanager\EndOfLife::configData(),
        ];
    }
}

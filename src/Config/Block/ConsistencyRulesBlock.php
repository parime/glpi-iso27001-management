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

/** Issue #614 : contrôles de cohérence du SMSI. */
final class ConsistencyRulesBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'risks';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_consistency_rules.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-consistency-rules'];
    }

    public static function data(): array
    {
        return [
            'consistency_rows'   => \GlpiPlugin\Grcmanager\ConsistencyCheck::getConfigRows(),
            'consistency_levels' => array_slice(\GlpiPlugin\Grcmanager\Risk::getRiskLevels(), 1, null, true),
            'consistency_url'    => \GlpiPlugin\Grcmanager\ConsistencyCheck::getSearchURL(),
        ];
    }
}

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

/** Issue #403 : référentiels de conformité actifs. */
final class FrameworksBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'frameworks';
    }

    public static function order(): int
    {
        return 10;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-frameworks-config'];
    }

    public static function data(): array
    {
        return [
            'frameworks'            => \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::frameworks(),
            'custom_frameworks_url' => \GlpiPlugin\Grcmanager\CustomFramework::getSearchURL(),
            'frameworks_help'       => \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::helpTexts(),
            'frameworks_enabled'    => \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::enabled(),
        ];
    }
}

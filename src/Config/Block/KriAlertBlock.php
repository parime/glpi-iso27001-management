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

/** Issue #378 : niveau d'alerte des indicateurs de risque. */
final class KriAlertBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'risks';
    }

    public static function order(): int
    {
        return 30;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_kri_alert.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-kri-alert-config'];
    }

    public static function data(): array
    {
        return [
            'kri_alert_level'  => \GlpiPlugin\Grcmanager\KriAlert::level(),
            'kri_alert_levels' => \GlpiPlugin\Grcmanager\KriAlert::getLevels(),
        ];
    }
}

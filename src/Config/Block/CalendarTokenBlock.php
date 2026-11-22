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

/** Issue #664 : durée de vie des liens d'abonnement au calendrier. */
final class CalendarTokenBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'general';
    }

    public static function order(): int
    {
        return 30;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_calendar_token.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-calendar-token-config'];
    }

    public static function data(): array
    {
        return [
            'calendar_token_lifetime' => \GlpiPlugin\Grcmanager\CalendarToken::lifetimeDays(),
            'calendar_token_bounds'   => [
                \GlpiPlugin\Grcmanager\CalendarToken::MIN_LIFETIME_DAYS,
                \GlpiPlugin\Grcmanager\CalendarToken::MAX_LIFETIME_DAYS,
            ],
        ];
    }
}

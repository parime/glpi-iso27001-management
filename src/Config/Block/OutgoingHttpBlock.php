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

/** Issue #658 : adresses internes pour les appels sortants. */
final class OutgoingHttpBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 90;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_outgoing_http.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-outgoing-http'];
    }

    public static function data(): array
    {
        return [
            'http_allow_private'       => \GlpiPlugin\Grcmanager\Compat\Http::allowsPrivateNetworks(),
            'http_can_edit_private'    => (bool) \Session::haveRight('config', UPDATE),
            'http_glpi12_context_hint' => \GlpiPlugin\Grcmanager\Compat\Http::glpi12ContextHint(),
        ];
    }
}

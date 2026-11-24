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
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitorConfig;
use GlpiPlugin\Grcmanager\Services\Breach\XposedOrNotClient;

/** Issue #183 : surveillance des fuites de données ; #1055 : fournisseur XposedOrNot ou Have I Been Pwned. */
final class BreachMonitoringBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'connectors';
    }

    public static function order(): int
    {
        return 20;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_breach_monitoring.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-breach-config'];
    }

    public static function data(): array
    {
        return [
            'breach_enabled'     => BreachMonitorConfig::isEnabled(),
            'breach_has_key'     => BreachMonitorConfig::hasApiKey(),
            'breach_has_xon_key' => BreachMonitorConfig::hasXonApiKey(),
            'breach_provider'    => BreachMonitorConfig::provider(),
            'breach_domains'     => implode(', ', BreachMonitorConfig::domains()),
            'breach_xon_limit'   => XposedOrNotClient::MAX_ACCOUNTS_PER_RUN,
        ];
    }
}

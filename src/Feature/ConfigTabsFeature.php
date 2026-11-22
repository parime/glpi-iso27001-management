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

namespace GlpiPlugin\Grcmanager\Feature;

/**
 * Issue #1036 : aide « À quoi sert cette page ? » de la configuration du plugin en onglets.
 */
final class ConfigTabsFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'config' => [
                'purpose'  => __(
                    'Gathers the settings of the plugin, by tab: frameworks, risk method, audits and evidence, '
                        . 'people and policies, incidents, connectors and fleet. Daily work stays in the menus.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Go through each tab once when you set up the plugin, then come back only to change a setting. '
                        . 'A tab is shown when you have the right to at least one of its settings.',
                    'grcmanager'
                ),
            ],
        ];
    }
}

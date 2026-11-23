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

use GlpiPlugin\Grcmanager\Cartography;
use GlpiPlugin\Grcmanager\CausalLink;
use GlpiPlugin\Grcmanager\ImpactAnalysis;

/**
 * Issue #1082 : cartographie du SI par couches, sous-page de la rubrique « Analyses de risques »
 * (à côté de l'analyse d'impact et de l'effet domino), et son aide de page.
 */
final class CartographyFeature implements MenuPagesProvider, PageHelpProvider
{
    public static function menuPages(): array
    {
        return [
            ImpactAnalysis::class => [
                Cartography::class => ['after' => CausalLink::class],
            ],
        ];
    }

    public static function pageHelp(): array
    {
        return [
            'cartography' => [
                'purpose'   => __(
                    'Shows the information system layer by layer, from the network to the business processes: '
                        . 'network equipment and cables, servers, workstations and virtual machines, installed '
                        . 'software and applications, then the processes of the business impact analysis. Choose '
                        . 'an item to see what its failure affects, up to the processes and their RTO, or what a '
                        . 'process depends on, down to the network.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Nothing is drawn by hand: the map reads what GLPI and GRC already know (network connections, '
                        . 'virtual machines, installed software, appliances, GLPI impact analysis, dependencies of '
                        . 'the business processes). A missing link is completed in GLPI or on the process; two '
                        . 'connected switches do not spread the impact, declare it in the GLPI impact analysis.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.9 and A.5.30', 'grcmanager'),
            ],
        ];
    }
}

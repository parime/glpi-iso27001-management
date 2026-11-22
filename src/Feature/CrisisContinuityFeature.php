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
 * Issue #1041 : aide de la page d'accueil « Assistance > Crise et continuité » (CrisisContinuity),
 * déclarée ici plutôt que dans la liste commune de PageHelp.
 */
final class CrisisContinuityFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'crisiscontinuity' => [
                'purpose'   => __(
                    'Everything needed to prepare for a crisis and keep the activity going: business processes '
                        . 'and their impact analysis, crisis scenarios, crisis exercises, recovery tests and '
                        . 'ready-to-use crisis messages.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Start with the cards that show something to do (recovery tests overdue or failed), then '
                        . 'keep each list up to date. Each card only appears if your profile can open its screen.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.29 and A.5.30', 'grcmanager'),
            ],
        ];
    }
}

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

use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\Nis2Readiness;

/**
 * Issue #1158 : parcours de mise en conformité NIS2. Sous-page de la rubrique « Pilotage », juste
 * après la préparation à la certification ISO 27001, et aide de la page, déclarées ici plutôt que
 * dans les listes communes (voir FeatureRegistry).
 */
final class Nis2ReadinessFeature implements MenuPagesProvider, PageHelpProvider
{
    public static function menuPages(): array
    {
        return [
            Dashboard::class => [
                Nis2Readiness::class => ['after' => CertificationReadiness::class],
            ],
        ];
    }

    public static function pageHelp(): array
    {
        return [
            'nis2readiness' => [
                'purpose'   => __(
                    'This page guides you through the NIS2 obligations, criterion by criterion: for each one, it '
                        . 'cites the article of the directive, shows where you stand according to the data '
                        . 'already entered, and gives the screen to open to complete it, with its place in the '
                        . 'menu.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Follow the criteria in order, starting with the one marked as the next step. Click its button '
                        . 'to open the screen concerned, complete it, then come back: the page is computed again '
                        . 'each time. Nothing is changed automatically. In France, enable the ReCyF of the ANSSI '
                        . 'to follow its measures, the missing evidence and the recurring actions.',
                    'grcmanager'
                ),
                'reference' => __(
                    'Directive (EU) 2022/2555 (NIS2), articles 3, 20, 21, 23 and 32; ReCyF of the ANSSI',
                    'grcmanager'
                ),
            ],
        ];
    }
}

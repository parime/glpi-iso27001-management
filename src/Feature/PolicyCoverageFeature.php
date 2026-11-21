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
 * Issue #879 : couverture des mesures de la SoA par les textes de la bibliothèque des politiques.
 * Aide de la page du rapport, déclarée ici plutôt que dans les listes communes (voir FeatureRegistry).
 */
final class PolicyCoverageFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'policycoverage' => [
                'purpose'   => __(
                    'Shows, for each applicable control of the Statement of Applicability, the texts of the policy '
                        . 'library that cover it, and lists the controls covered by no text, or only by a text not '
                        . 'approved yet or whose review date has passed.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Open each policy, then its "Covered controls" tab, and link the whole text or one of its '
                        . 'sections to the controls it deals with (several at once, or by typing codes such as '
                        . 'A.5.15-A.5.18). Then write, approve or review the missing texts. Export the report in '
                        . 'PDF or CSV for the auditor. Nothing is changed automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 5.2 and control A.5.1', 'grcmanager'),
            ],
        ];
    }
}

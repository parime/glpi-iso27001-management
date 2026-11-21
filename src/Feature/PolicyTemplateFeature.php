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
 * Issue #545 : catalogue de modèles de politiques prêts à adapter. Aide de la page, déclarée ici
 * plutôt que dans les listes communes (voir FeatureRegistry).
 */
final class PolicyTemplateFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'policytemplate' => [
                'purpose'   => __(
                    'Gives a starting point to an organisation that has no security policy yet: ready-to-adapt '
                        . 'templates written by the project, each with its sections and the Annex A controls it '
                        . 'covers. The templates follow the requirements of the standard in our own words.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Fill in the name of the organisation, the information security manager and the review '
                        . 'period, tick the templates you need, then import them. Each one becomes a new draft '
                        . 'policy, linked by section to the controls of the Statement of Applicability. Adapt the '
                        . 'text to your practice, then have it approved: nothing is approved automatically and no '
                        . 'existing policy is replaced.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 5.2 and control A.5.1', 'grcmanager'),
            ],
        ];
    }
}

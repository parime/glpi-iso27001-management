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
 * Issue #873 : cadrage guidé de la SoA. Aide de la page, déclarée ici (voir FeatureRegistry).
 */
final class SoaScopingFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'soascoping' => [
                'purpose'   => __(
                    'Helps you decide and justify the applicability of each Annex A control: a few questions '
                        . 'about your context (remote work, software development, suppliers, cloud, premises, '
                        . 'personal data) give a proposed applicability and justification for every control of '
                        . 'the Statement of Applicability.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Answer the questions, then accept or reject each proposal, one by one or in bulk after a '
                        . 'preview. Nothing is applied without your decision, a written justification is never '
                        . 'overwritten, and every decision is logged with its author and date. Export the CSV '
                        . 'for the auditor.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 6.1.3 c) and d)', 'grcmanager'),
            ],
        ];
    }
}

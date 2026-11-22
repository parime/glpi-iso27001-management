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
 * Issue #872 : risques signalés par les collaborateurs, puis triés. Aide des deux pages, déclarée
 * ici (voir FeatureRegistry).
 */
final class ReportedRiskFeature implements PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'reportedrisk.mine' => [
                'purpose'   => __(
                    'Lets anyone in the organisation point out a risk or a dangerous situation they noticed, '
                        . 'without needing access to the risk register.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Give a short title and describe the situation, then send. You only see your own reports '
                        . 'here, with the answer of the person in charge of risks once it is examined.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 6.1.2 and control A.6.8', 'grcmanager'),
            ],
            'reportedrisk' => [
                'purpose'   => __(
                    'Triage of the risks reported by staff: each report is examined by a person before anything '
                        . 'reaches the risk register.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each new report, add it to the register (a risk is created, to be completed), link it '
                        . 'to an existing risk, or reject it with a reason. The author is informed of the '
                        . 'decision; the report stays visible on the risk as a trace for the auditor.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 6.1.2 and control A.6.8', 'grcmanager'),
            ],
        ];
    }
}

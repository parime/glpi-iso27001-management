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

use GlpiPlugin\Grcmanager\ApprovedApplication;

/**
 * Issue #622 : applications et services en ligne approuvés (mesure A.5.23). Aide de la page et cadre
 * de preuve de la mesure, déclarés ici plutôt que dans les listes communes (voir FeatureRegistry).
 */
final class ApprovedApplicationFeature implements ControlEvidenceProvider, PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'approvedapplication' => [
                'purpose'   => __(
                    'Lists the applications and online services that may be used, what for and with which data: '
                        . 'approved, approved with restrictions, under evaluation or refused. Each decision is '
                        . 'recorded in the "Approved use" tab of an appliance of GLPI, next to its secure '
                        . 'development sheet. Users see the approved ones in their preferences, without any right '
                        . 'on the plugin.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Complete the approved applications (owner, allowed uses, data level), review them when due '
                        . '(a reassessment ticket is opened for the owner), and check the installed software that is '
                        . 'refused or missing from the register. Nothing is uninstalled or changed automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.5.23', 'grcmanager'),
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Approved applications and online services', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_approved_application_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ApprovedApplication::evidence($code);
    }
}

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

use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\CrisisContinuity;
use GlpiPlugin\Grcmanager\CrisisScenario;

/**
 * Issue #1083 : plans de continuité et de reprise (PCA, PRA, PCI, PRI). Sous-page de « Assistance >
 * Crise et continuité » (groupe « Se préparer », après les scénarios de crise), aide de la page et
 * cadre de preuve des mesures A.5.29 et A.5.30 de la SoA, déclarés ici plutôt que dans les listes
 * communes (voir FeatureRegistry).
 */
final class ContinuityPlanFeature implements MenuPagesProvider, PageHelpProvider, ControlEvidenceProvider
{
    public static function menuPages(): array
    {
        return [
            CrisisContinuity::class => [
                ContinuityPlan::class => [
                    'group' => __('Prepare', 'grcmanager'),
                    'after' => CrisisScenario::class,
                ],
            ],
        ];
    }

    public static function pageHelp(): array
    {
        return [
            'continuityplan' => [
                'purpose'   => __(
                    'The continuity and recovery plans of the organisation: business continuity and recovery '
                        . 'plans (BCP, BRP) for activities, IT continuity and disaster recovery plans (ITCP, DRP) '
                        . 'for systems. Each plan gathers the covered processes with their recovery objectives, '
                        . 'the crisis team, the steps, the fallback solutions and the communication.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Create one plan per critical activity or system, link the covered processes, then have it '
                        . 'approved by management (Versions and approval tab) and test it at least once a year '
                        . '(Plan tests tab). Print the approved plan in PDF to keep a copy outside GLPI.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.29 and A.5.30; ISO 22301', 'grcmanager'),
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Continuity and recovery plans', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_continuity_plan_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ContinuityPlan::evidence($code);
    }
}

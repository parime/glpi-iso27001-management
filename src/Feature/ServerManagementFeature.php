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

use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #843 : contrôleurs de gestion des serveurs (iLO, iDRAC, XClarity, IPMI). Aide de la page, source
 * de KRI et cadre de preuve des mesures A.8.2, A.8.9 et A.8.22, déclarés ici plutôt que dans les listes
 * communes (voir FeatureRegistry).
 */
final class ServerManagementFeature implements
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'servermanagement' => [
                'purpose'   => __(
                    'Lists the remote management controllers of the servers (HPE iLO, Dell iDRAC, Lenovo XClarity '
                        . 'Controller, Supermicro IPMI): they give full control of a server, even switched off, and '
                        . 'are often forgotten. The page shows their firmware, their risky settings, their exposure '
                        . 'outside the administration networks and their local accounts, and the physical servers '
                        . 'without a known controller.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Declare each controller with a local read-only account and click "Read now" (Redfish, read '
                        . 'only), or import a CSV file for controllers with IPMI only. Then fix the gaps on the '
                        . 'controllers yourself and review their accounts: the plugin never changes anything on the '
                        . 'servers.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.2, A.8.9 and A.8.22', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Contrôleurs de gestion des serveurs avec un écart grave.
            'bmc_serious_gaps' => [
                'label'     => __('Server management controllers with a serious gap', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [ServerManagement::class, 'riskyControllers'],
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Server management controllers', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_server_management_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ServerManagement::evidence($code);
    }
}

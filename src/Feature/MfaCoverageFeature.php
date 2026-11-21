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

use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #852 : couverture de l'authentification multifacteur (MFA) par compte. Aide de la page, carte du
 * tableau de bord, source de KRI et cadre de preuve des mesures A.8.5 et A.5.17, déclarés ici plutôt que dans
 * les listes communes (voir FeatureRegistry).
 */
final class MfaCoverageFeature implements
    DashboardCardsProvider,
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'mfacoverage' => [
                'purpose'   => __(
                    'Shows which active accounts are protected by multi-factor authentication: GLPI two-factor '
                        . 'authentication, and the methods registered with your identity provider (Microsoft Entra '
                        . 'ID, Okta, Google Workspace, on-premises solution) imported from a CSV export. Rates are '
                        . 'given for all accounts, administrators and remote access accounts.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Import the export of your identity provider, choose the remote access groups, then follow up '
                        . 'the gaps: administrator or remote access without MFA, SMS or call only, account excluded '
                        . 'without justification. Nothing is changed automatically. Record the result as an '
                        . 'effectiveness test of the control to keep the evidence.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.5 and A.5.17', 'grcmanager'),
            ],
        ];
    }

    public static function dashboardCards(): array
    {
        return [
            // Administrateurs protégés par MFA (%), à sa place historique (après le chiffrement et l'AD).
            'grcmanager_mfa_admin_rate' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Administrators protected by MFA (%)', 'grcmanager'),
                'provider'   => SecuredDashboardCards::class . '::mfaAdminRate',
                'after'      => 'grcmanager_ad_security_score',
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Administrateurs sans authentification multifacteur.
            'admins_without_mfa' => [
                'label'     => __('Administrators without MFA', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['user' => READ],
                'global'    => false,
                'compute'   => [MfaCoverage::class, 'adminsWithoutMfa'],
                'after'     => 'unencrypted_laptops',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('MFA coverage', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_mfa_coverage_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return MfaCoverage::evidence($code);
    }
}

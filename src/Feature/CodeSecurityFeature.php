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

use GlpiPlugin\Grcmanager\CodeSecurity;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #858 : sécurité du code lue dans GitHub et GitLab (lecture seule). Aide de la page, deux
 * sources de KRI et cadre de preuve des mesures A.8.28 et A.8.29, déclarés ici plutôt que dans les
 * listes communes (voir FeatureRegistry).
 */
final class CodeSecurityFeature implements RiskIndicatorSourcesProvider, ControlEvidenceProvider, PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'codesecurity' => [
                'purpose'   => __(
                    'Shows the security alerts of the code of your applications, read from GitHub and GitLab in '
                        . 'read only: vulnerable third-party libraries, passwords or keys written in the code, and '
                        . 'weaknesses found by the code analysis. The open alerts are classed by severity and age '
                        . 'and compared with the remediation deadlines of your policy; an exposed secret that has '
                        . 'not been revoked comes first.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enter the code repository of each application in its "Secure development" tab, save a '
                        . 'read-only token for GitHub or GitLab, then click "Read now". Then revoke the exposed '
                        . 'secrets, follow up the alerts past their deadline and enable the missing analyses. '
                        . 'Nothing is changed in GitHub or GitLab and nothing is created automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.28 and A.8.29', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Secrets exposés dans le code que rien ne dit révoqués.
            'codesecurity_secrets' => [
                'label'     => __('Exposed secrets not revoked in the code (GitHub, GitLab)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['appliance' => READ],
                'global'    => false,
                'compute'   => [CodeSecurity::class, 'secretsNotRevoked'],
                'after'     => 'uptimekuma_exposed_without_monitor',
            ],
            // Alertes ouvertes au-delà du délai de la politique de correction.
            'codesecurity_overdue' => [
                'label'     => __('Code security alerts past their deadline (GitHub, GitLab)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['appliance' => READ],
                'global'    => false,
                'compute'   => [CodeSecurity::class, 'overdueAlerts'],
                'after'     => 'codesecurity_secrets',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Code security (GitHub, GitLab)', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_code_security_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return CodeSecurity::evidence($code);
    }
}

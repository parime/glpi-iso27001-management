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

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\PrivilegedVault;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #859 : comptes à privilèges et coffre-fort de mots de passe. Sous-page de la rubrique
 * « Accès et comptes », aide de la page, source de KRI et cadre de preuve des mesures A.5.17 et
 * A.8.2, déclarés ici plutôt que dans les listes communes (voir FeatureRegistry).
 */
final class PrivilegedVaultFeature implements
    MenuPagesProvider,
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function menuPages(): array
    {
        return [
            AccessReview::class => [
                PrivilegedVault::class => ['after' => MfaCoverage::class],
            ],
        ];
    }

    public static function pageHelp(): array
    {
        return [
            'privilegedvault' => [
                'purpose'   => __(
                    'Shows the shared and privileged accounts kept in your password vault (Bitwarden, '
                        . 'Vaultwarden or a privileged access management solution) and who can reach them: when '
                        . 'each password was last changed, the members of the vault and their role. Only '
                        . 'metadata is read, never a password.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Import the items and the members of the vault (or read the members from Bitwarden), then '
                        . 'follow up the gaps: password not changed within the expected delay, access of a '
                        . 'person who left, member unknown in GLPI, vault administrator without two-step login, '
                        . 'server controller administrator account missing from the vault. Nothing is changed '
                        . 'automatically. The gaps are also shown in the access review campaigns.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.17 and A.8.2', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Secrets du coffre non renouvelés dans le délai attendu.
            'vault_stale_secrets' => [
                'label'     => __('Shared passwords not changed within the expected delay', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['user' => READ],
                'global'    => false,
                'compute'   => [PrivilegedVault::class, 'staleSecrets'],
                'after'     => 'admins_without_mfa',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Privileged accounts and password vault', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_privileged_vault_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return PrivilegedVault::evidence($code);
    }
}

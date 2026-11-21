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

use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;

/**
 * Issue #838 : couverture des sauvegardes. Aide de la page, source de KRI et cadre de preuve des
 * mesures A.8.13 et A.5.30, déclarés ici plutôt que dans les listes communes (voir FeatureRegistry).
 */
final class BackupCoverageFeature implements
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'backupcoverage' => [
                'purpose'   => __(
                    'Shows which important assets have a recent successful backup (Veeam Backup & Replication, '
                        . 'Acronis, Commvault, Synology, Proxmox Backup Server), which have none or one older than '
                        . 'their recovery point objective (RPO), whose last backup failed, and when their restore '
                        . 'was last tested.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Choose the assets that must be backed up, read Veeam or import the list of machines of each '
                        . 'backup tool, set the RPO of the assets or of their business processes, and record the '
                        . 'restore tests in the recovery exercises. The page only reads: it never starts a backup. '
                        . 'Record the result as an effectiveness test of the control to keep the evidence.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.13 and A.5.30', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Actifs critiques sans sauvegarde récente au regard de leur RPO.
            'critical_assets_without_backup' => [
                'label'     => __('Critical assets without a recent backup (%)', 'grcmanager'),
                'unit'      => '%',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [BackupCoverage::class, 'criticalWithoutRecentBackup'],
                'after'     => 'critical_assets_without_logging',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Backup coverage', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_backup_coverage_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return BackupCoverage::evidence($code);
    }
}

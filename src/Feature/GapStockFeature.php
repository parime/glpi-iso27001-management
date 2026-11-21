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

use GlpiPlugin\Grcmanager\GapStockReport;

/**
 * Issue #870 : rapport du stock d'écarts (clauses 10.1 et 10.2). Aide de la page, carte du tableau
 * de bord et cadre de preuve des exigences 10.1 et 10.2, déclarés ici (voir FeatureRegistry).
 */
final class GapStockFeature implements DashboardCardsProvider, ControlEvidenceProvider, PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'gapstock' => [
                'purpose'   => __(
                    'Shows the backlog of non-conformities, other findings and corrective actions, and of the '
                        . 'actions decided in management reviews: what is still open by severity, origin, entity '
                        . 'and owner, how old it is, what is overdue, the mean time to close over a period, the '
                        . 'monthly trend and whether the effectiveness of closed actions was verified.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Choose the period and the filters, follow up the overdue items with their owners, then '
                        . 'export the report in PDF or CSV for the auditor, or archive it as evidence of '
                        . 'requirements 10.1 and 10.2: the evidence pack then includes it. Nothing is changed '
                        . 'automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clauses 9.1, 10.1 and 10.2', 'grcmanager'),
            ],
        ];
    }

    public static function dashboardCards(): array
    {
        return [
            // Délai moyen de clôture, après les CAPA en retard.
            'grcmanager_gap_mean_closure' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Mean time to close gaps (days, 12 months)', 'grcmanager'),
                'provider'   => GapStockReport::class . '::dashboardCard',
                'after'      => 'grcmanager_overdue_capa',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return GapStockReport::getTypeName();
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_gap_stock_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return GapStockReport::evidence($code);
    }
}

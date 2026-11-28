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

use GlpiPlugin\Grcmanager\RiskEffectiveness;

/**
 * Issue #1182 : cartes du tableau de bord « plans de traitement terminés sans atteindre la cible »
 * et « acceptations de risque à renouveler », placées après celle des risques à revoir. Leurs
 * fournisseurs refusent eux-mêmes sans la lecture du registre des risques (RiskEffectiveness).
 */
final class RiskTreatmentEffectivenessFeature implements DashboardCardsProvider
{
    public static function dashboardCards(): array
    {
        return [
            'grcmanager_risks_target_not_reached' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Treatment plans completed without reaching the target', 'grcmanager'),
                'provider'   => RiskEffectiveness::class . '::targetNotReachedCard',
                'after'      => 'grcmanager_risks_pending_review',
            ],
            'grcmanager_risk_acceptances_to_renew' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Risk acceptances to renew', 'grcmanager'),
                'provider'   => RiskEffectiveness::class . '::acceptancesToRenewCard',
                'after'      => 'grcmanager_risks_target_not_reached',
            ],
        ];
    }
}

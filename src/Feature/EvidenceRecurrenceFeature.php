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

use GlpiPlugin\Grcmanager\EvidenceRecurrence;

/**
 * Issue #886 : carte du tableau de bord des preuves récurrentes non renouvelées à temps, placée après
 * celle des preuves expirées ou à renouveler (issue #145).
 */
final class EvidenceRecurrenceFeature implements DashboardCardsProvider
{
    public static function dashboardCards(): array
    {
        return [
            'grcmanager_evidences_stale' => [
                'widgettype' => ['bigNumber'],
                'label'      => __('Recurring evidence not renewed in time', 'grcmanager'),
                'provider'   => EvidenceRecurrence::class . '::staleCard',
                'after'      => 'grcmanager_evidences_to_renew',
            ],
        ];
    }
}

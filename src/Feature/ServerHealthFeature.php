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

use CommonDBTM;
use Computer;
use GlpiPlugin\Grcmanager\AssetSecurity360;
use GlpiPlugin\Grcmanager\ServerHealth;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;

/**
 * Issue #843, lot 3 : source « Santé du serveur » de la vue Sécurité 360° (#837) : composants dégradés
 * (alimentations, disques, ventilateurs, températures) et alertes récentes du journal du contrôleur,
 * lus par Redfish en lecture seule. Absente sans contrôleur ou sans santé lue.
 */
final class ServerHealthFeature implements AssetSecuritySourcesProvider
{
    public static function assetSecuritySources(): array
    {
        return [
            'server_health' => [
                'label'     => __('Server health', 'grcmanager'),
                'icon'      => 'ti ti-heart-rate-monitor',
                'itemtypes' => [Computer::class],
                'weight'    => 1,
                'compute'   => [self::class, 'serverHealth'],
                'after'     => 'management_controller',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function serverHealth(CommonDBTM $item): ?array
    {
        if (!ServerManagement::canView()) {
            return null;
        }
        $rows = ServerManagement::tabData($item)['rows'];
        $healths = array_map(static fn (array $row): array => $row['health_detail'], $rows);
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $sum = ServerHealth::summarize($healths, $now);
        if (!$sum['has_data']) {
            return null;
        }
        $facts = [];
        if ($sum['critical'] > 0) {
            $facts[] = sprintf(__('%d component(s) in critical state', 'grcmanager'), $sum['critical']);
        }
        if ($sum['warning'] > 0) {
            $facts[] = sprintf(__('%d component(s) with a warning', 'grcmanager'), $sum['warning']);
        }
        if ($sum['recent_alerts'] > 0) {
            $facts[] = sprintf(
                __('%1$d alert(s) in the controller log over the last %2$d days', 'grcmanager'),
                $sum['recent_alerts'],
                ServerHealth::RECENT_DAYS
            );
        }
        if ($sum['critical'] > 0 || $sum['recent_critical'] > 0) {
            $status = Rules::STATUS_CRITICAL;
            $summary = __('A component or a recent event of the server is critical.', 'grcmanager');
        } elseif ($facts !== []) {
            $status = Rules::STATUS_WARNING;
            $summary = __('A component or a recent event of the server needs attention.', 'grcmanager');
        } else {
            $status = Rules::STATUS_OK;
            $summary = __('Power supplies, disks, fans and temperatures read as healthy.', 'grcmanager');
        }

        return [
            'status'  => $status,
            'summary' => $summary,
            'facts'   => $facts,
            'url'     => AssetSecurity360::tabUrl($item, ServerManagement::class),
        ];
    }
}

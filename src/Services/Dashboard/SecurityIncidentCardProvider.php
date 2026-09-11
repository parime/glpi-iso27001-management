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

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use PluginGrcmanagerSecurityIncident;

/**
 * The one security-incident dashboard card that needs a custom query — everything else (total
 * count, breakdown by entity/category) is served directly by GLPI core's own generic
 * `Glpi\Dashboard\Provider::bigNumber<Itemtype>`/`multipleNumber<Itemtype>By<FkItemtype>`
 * (`__callStatic` magic methods, already handling entity restriction/`is_deleted` for any
 * `CommonDBTM`), registered directly by class name in `hook.php` rather than reimplemented here.
 * "Currently open" has no such generic equivalent since it depends on this plugin's own status
 * constants. Ported from the absorbed glpi-security-incidents plugin (ROADMAP.md "Version 2.0").
 */
final class SecurityIncidentCardProvider
{
    /**
     * @return array{number: int, url: string, label: string, icon: string}
     */
    public static function open(array $params = []): array
    {
        global $DB;

        $table          = PluginGrcmanagerSecurityIncident::getTable();
        $closedStatuses = array_merge(
            PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
            PluginGrcmanagerSecurityIncident::getClosedStatusArray()
        );

        $where = [
            'is_deleted' => 0,
            'NOT'        => ['status' => $closedStatuses],
        ] + \getEntitiesRestrictCriteria($table, '', '', true);

        $count = (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => $table,
            'WHERE' => $where,
        ])->current()['cpt'];

        return [
            'number' => $count,
            'url'    => PluginGrcmanagerSecurityIncident::getSearchURL()
                . '?criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=notold&reset=reset',
            'label'  => _x('security incidents', 'Open', 'grcmanager'),
            'icon'   => PluginGrcmanagerSecurityIncident::getIcon(),
        ];
    }
}

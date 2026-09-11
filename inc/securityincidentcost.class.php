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

/**
 * Required, not optional — same class of surprise as `PluginGrcmanagerSecurityIncidentTemplate`
 * (see its own docblock): `NotificationTargetCommonITILObject::getDataForObject()` unconditionally
 * builds `$item->getType() . 'Cost'` and calls `$costtype::getCostsSummary(...)` on it with no
 * existence check at all — confirmed the hard way, the first real notification fired after
 * `PluginGrcmanagerSecurityIncidentTemplate` was added still fataled with
 * `Class "PluginGrcmanagerSecurityIncidentCost" not found`. Same minimal shape as GLPI
 * core's own `ChangeCost`.
 */
class PluginGrcmanagerSecurityIncidentCost extends CommonITILCost
{
    public static $itemtype = PluginGrcmanagerSecurityIncident::class;

    public static $items_id = 'plugin_grcmanager_securityincidents_id';

    public static function canCreate(): bool
    {
        return Session::haveRight('plugin_grcmanager_securityincident', UPDATE);
    }

    public static function canView(): bool
    {
        return Session::haveRightsOr('plugin_grcmanager_securityincident', [
           PluginGrcmanagerSecurityIncident::READALL,
           PluginGrcmanagerSecurityIncident::READMY,
        ]);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('plugin_grcmanager_securityincident', UPDATE);
    }
}

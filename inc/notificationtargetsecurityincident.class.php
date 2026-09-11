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
 * Resolved automatically by `NotificationTarget::getInstanceClass()` for the legacy-global-
 * namespace `PluginGrcmanagerSecurityIncident` itemtype (`isPluginItemType()` parses
 * "Securityincidents" as the plugin key and "SecurityIncident" as the class, producing
 * `Plugin` + `Securityincidents` + `NotificationTarget` + `SecurityIncident` — confirmed by
 * reading GLPI core, no extra hook registration needed). Same minimal shape as core's own
 * `NotificationTargetChange`: only the event labels are specific to this plugin.
 */
class PluginGrcmanagerNotificationTargetSecurityIncident extends NotificationTargetCommonITILObject
{
    public function getEvents()
    {
        return [
           'new' => __('New security incident', 'grcmanager'),
           'update' => __('Update of a security incident', 'grcmanager'),
           'solved' => __('Security incident solved', 'grcmanager'),
           'closed' => __('Security incident closed', 'grcmanager'),
        ];
    }
}

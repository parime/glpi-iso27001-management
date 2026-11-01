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

use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentUserBase;

/**
 * Relation between PluginGrcmanagerSecurityIncident and User (requester/observer/assign) -
 * same minimal shape as GLPI core's own `Change_User`.
 */
class PluginGrcmanagerSecurityIncident_User extends SecurityIncidentUserBase
{
    public const ITEMTYPE_1 = PluginGrcmanagerSecurityIncident::class;
    public const ITEMS_ID_1 = 'plugin_grcmanager_securityincidents_id';
    public const ITEMTYPE_2 = User::class;
    public const ITEMS_ID_2 = 'users_id';
}

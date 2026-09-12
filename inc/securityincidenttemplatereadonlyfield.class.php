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
 * Required by `ITILTemplate` (naming-convention resolved) — same minimal shape as GLPI core's own
 * `ChangeTemplateReadonlyField`.
 */
class PluginGrcmanagerSecurityIncidentTemplateReadonlyField extends ITILTemplateReadonlyField
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_readonlyfields';
    }

    public static $itemtype = PluginGrcmanagerSecurityIncidentTemplate::class;

    public static $items_id = 'securityincidenttemplates_id';

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;
}

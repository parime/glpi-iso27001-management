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
 * Required by `ITILTemplate` (naming-convention resolved, `$itiltype . 'TemplateHiddenField'`) —
 * same minimal shape as GLPI core's own `ChangeTemplateHiddenField`.
 */
class PluginGrcmanagerSecurityIncidentTemplateHiddenField extends ITILTemplateHiddenField
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_hiddenfields';
    }

    public static $itemtype = PluginGrcmanagerSecurityIncidentTemplate::class;

    public static $items_id = 'securityincidenttemplates_id';

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;
}

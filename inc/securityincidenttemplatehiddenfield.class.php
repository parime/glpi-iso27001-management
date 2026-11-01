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

use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentTemplateHiddenFieldBase;

/**
 * Required by `ITILTemplate` (naming-convention resolved, `$itiltype . 'TemplateHiddenField'`) -
 * same minimal shape as GLPI core's own `ChangeTemplateHiddenField`.
 */
class PluginGrcmanagerSecurityIncidentTemplateHiddenField extends SecurityIncidentTemplateHiddenFieldBase
{
    public const ITEMTYPE = PluginGrcmanagerSecurityIncidentTemplate::class;
    public const ITEMS_ID = 'securityincidenttemplates_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_hiddenfields';
    }

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;
}

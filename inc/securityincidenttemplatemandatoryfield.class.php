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

use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentTemplateMandatoryFieldBase;

/**
 * Required by `ITILTemplate` (naming-convention resolved): same minimal shape as GLPI core's own
 * `ChangeTemplateMandatoryField`.
 */
class PluginGrcmanagerSecurityIncidentTemplateMandatoryField extends SecurityIncidentTemplateMandatoryFieldBase
{
    public const ITEMTYPE = PluginGrcmanagerSecurityIncidentTemplate::class;
    public const ITEMS_ID = 'securityincidenttemplates_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_secincidenttemplates_mandatoryfields';
    }

    public static $itiltype = PluginGrcmanagerSecurityIncident::class;
}

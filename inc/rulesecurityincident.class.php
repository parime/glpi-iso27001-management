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
 * Paired with `RulePluginGrcmanagerSecurityIncidentCollection` — see that class's own
 * docblock for why this exists. Minimal shape, mirroring GLPI core's own `RuleChange`.
 */
class RulePluginGrcmanagerSecurityIncident extends RuleCommonITILObject
{
    public static $rightname = 'rule_grcmanager_securityincident';

    public function getTitle()
    {
        return __('Business rules for security incidents', 'grcmanager');
    }

    public function getTargetItilType(): CommonITILObject
    {
        return new PluginGrcmanagerSecurityIncident();
    }
}

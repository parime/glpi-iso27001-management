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
 * Investigation/remediation follow-ups on a PluginGrcmanagerSecurityIncident — same shape
 * as GLPI core's own `ChangeTask`. `CommonITILTask::getItilObjectItemType()` derives the parent
 * itemtype by stripping the literal string "Task" off `static::class`, which resolves to
 * `PluginGrcmanagerSecurityIncident` automatically; no override needed here.
 */
class PluginGrcmanagerSecurityIncidentTask extends CommonITILTask
{
    public static function getTypeName($nb = 0)
    {
        return _n('Security incident task', 'Security incident tasks', $nb, 'grcmanager');
    }
}

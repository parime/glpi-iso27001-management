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

// Same gap as securityincidenttask.form.php/securityincidentcost.form.php (see their own
// comments): the "Item" tab (associated assets) posts to `front/<itemtype>_item.form.php` by
// convention — confirmed live, the tab's own form action pointed here before this file existed.
// Delegates to GLPI core's shared generic controller, same as front/change_item.form.php.

$obj = new PluginGrcmanagerSecurityIncident();
$item_obj = new PluginGrcmanagerSecurityIncident_Item();
include(GLPI_ROOT . '/front/commonitilobject_item.form.php');

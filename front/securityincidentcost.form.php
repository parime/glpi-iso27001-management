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

// Same gap as securityincidenttask.form.php (see its own comment) for the same reason: the Cost
// tab's own "add a cost" subform (loaded via ajax/viewsubitem.php, itself generic) posts to
// `front/<itemtype-in-lowercase>.form.php` by convention — confirmed live, submitting it 403'd
// with no controller to receive it. Delegates to GLPI core's shared generic controller, same as
// every core ITIL cost front controller (front/changecost.form.php, front/ticketcost.form.php).

$cost = new PluginGrcmanagerSecurityIncidentCost();

include(GLPI_ROOT . '/front/commonitilcost.form.php');

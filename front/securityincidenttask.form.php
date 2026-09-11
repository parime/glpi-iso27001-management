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

// Required, not optional — the timeline's "add a task" action posts here by convention
// (`<itemtype in lowercase>task.form.php`, same as core's own front/changetask.form.php /
// front/tickettask.form.php). Missing entirely until now: confirmed live, adding a task from the
// incident's own timeline 404'd. Delegates to GLPI core's shared generic controller, same as every
// core ITIL task front controller does — no plugin-specific logic needed.

$task = new PluginGrcmanagerSecurityIncidentTask();

include(GLPI_ROOT . '/front/commonitiltask.form.php');

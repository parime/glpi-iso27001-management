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

// Issue #377 : enregistrer la BIA d'un processus métier exige de pouvoir modifier ce processus
// (droit et entité).
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, UPDATE);

if (isset($_POST['save'])) {
    $process = new \GlpiPlugin\Grcmanager\BusinessProcess();
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    \GlpiPlugin\Grcmanager\BusinessImpact::saveGrid($process, (array) ($_POST['levels'] ?? []));
    Session::addMessageAfterRedirect(htmlescape(__('Business impact analysis saved.', 'grcmanager')));
}

Html::back();

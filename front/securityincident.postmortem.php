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

include('../../../inc/includes.php');

// Issue #169 : génère et archive le rapport post-incident (PDF) de l'incident.
Session::checkRight('document', CREATE);
$incident = new PluginGrcmanagerSecurityIncident();
if (!isset($_POST['generate']) || !$incident->getFromDB((int) ($_POST['id'] ?? 0)) || !$incident->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
// Archiver le rapport ajoute un document à l'incident : droit de modification sur l'incident.
if (!$incident->canUpdateItem()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$documentsId = PluginGrcmanagerSecurityIncidentPostMortem::archive($incident);
if ($documentsId > 0) {
    Session::addMessageAfterRedirect(htmlescape(__(
        'Post-incident report archived in the incident documents.',
        'grcmanager'
    )));
    Html::redirect(Document::getFormURLWithID($documentsId));
}
Session::addMessageAfterRedirect(htmlescape(__('The report could not be generated.', 'grcmanager')), false, ERROR);
Html::back();

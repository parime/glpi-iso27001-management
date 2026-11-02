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

// Issue #370 : onglet « Remédiation » de l'incident : appliquer une procédure, renseigner les
// tâches (statut, compte rendu, assignation), ajouter une tâche propre à l'incident.
use GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask;

$item = new SecurityIncidentPlaybookTask();
$incidentsId = (int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0);

if (isset($_POST['apply'])) {
    if (!SecurityIncidentPlaybookTask::canEditIncident($incidentsId)) {
        Html::displayRightError();
    }
    $playbooksId = (int) ($_POST['plugin_grcmanager_playbooks_id'] ?? 0);
    $created = SecurityIncidentPlaybookTask::applyPlaybook($incidentsId, $playbooksId);
    Session::addMessageAfterRedirect(sprintf(
        _n('%d remediation task added.', '%d remediation tasks added.', $created, 'grcmanager'),
        $created
    ), false, $created > 0 ? INFO : WARNING);
} elseif (isset($_POST['add'])) {
    if (!SecurityIncidentPlaybookTask::canEditIncident($incidentsId)) {
        Html::displayRightError();
    }
    $item->add($_POST);
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();

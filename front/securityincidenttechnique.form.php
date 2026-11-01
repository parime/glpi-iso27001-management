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

// Issue #369 : onglet « MITRE ATT&CK » de l'incident. Ajout depuis la liste ou par identifiant /
// nom, commentaire et retrait, toujours avec retour sur l'onglet de l'incident.
use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;

$item = new SecurityIncidentTechnique();

if (isset($_POST['add'])) {
    $incidentsId = (int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0);
    if (!SecurityIncidentTechnique::canEditIncident($incidentsId)) {
        Html::displayRightError();
    }
    $choice = trim((string) ($_POST['free_input'] ?? '')) !== ''
        ? (string) $_POST['free_input']
        : (string) ($_POST['technique'] ?? '');
    if (trim($choice) !== '') {
        SecurityIncidentTechnique::addFromInput($incidentsId, $choice, (string) ($_POST['comment'] ?? ''));
    }
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update(['id' => (int) $_POST['id'], 'comment' => (string) ($_POST['comment'] ?? '')]);
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();

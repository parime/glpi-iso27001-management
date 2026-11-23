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

// Issue #371 : onglet « Équipe d'intervention » de l'incident (ajout, rôle, retrait par un pilote).
use GlpiPlugin\Grcmanager\SecurityIncidentTeam;

// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

$item = new SecurityIncidentTeam();

if (isset($_POST['add'])) {
    $incident = new PluginGrcmanagerSecurityIncident();
    $incident->check((int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0), UPDATE);
    $item->add($_POST);
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update(['id' => (int) $_POST['id'], 'role' => (string) ($_POST['role'] ?? '')]);
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();

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

// Issue #370 : onglet « Journal des actions » de l'incident (ajout, modification, retrait) et PDF
// « Journal des actions et remédiation ».
use GlpiPlugin\Grcmanager\SecurityIncidentAction;

$item = new SecurityIncidentAction();

if (isset($_GET['pdf'])) {
    $incident = new PluginGrcmanagerSecurityIncident();
    if (!$incident->getFromDB((int) $_GET['pdf']) || !$incident->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $pdf = SecurityIncidentAction::renderPdf($incident);
    \GlpiPlugin\Grcmanager\AuditLog::record('export', SecurityIncidentAction::class, 0, (int) $incident->getID());
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . sprintf(
        'journal-actions-%s.pdf',
        preg_replace('/[^A-Za-z0-9-]/', '', (string) ($incident->fields['reference'] ?: $incident->getID()))
    ) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;

    return;
}

if (isset($_POST['add'])) {
    if (!SecurityIncidentAction::canEditIncident((int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0))) {
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

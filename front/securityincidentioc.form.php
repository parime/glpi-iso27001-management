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

// Issue #369 : onglet « IOC » de l'incident. Ajout en masse, qualification (verdict) et retrait,
// toujours avec retour sur l'onglet de l'incident.
use GlpiPlugin\Grcmanager\SecurityIncidentIoc;

$item = new SecurityIncidentIoc();

if (isset($_POST['add'])) {
    $incidentsId = (int) ($_POST['plugin_grcmanager_securityincidents_id'] ?? 0);
    if (!SecurityIncidentIoc::canEditIncident($incidentsId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $result = SecurityIncidentIoc::addFromText(
        $incidentsId,
        (string) ($_POST['iocs'] ?? ''),
        (string) ($_POST['verdict'] ?? '')
    );
    if ($result['added'] > 0 || $result['updated'] > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('%1$d indicator(s) added, %2$d already known (last seen date updated).', 'grcmanager'),
            $result['added'],
            $result['updated']
        ), false, INFO);
    }
    if ($result['rejected'] !== []) {
        Session::addMessageAfterRedirect(sprintf(
            __('Not recognized, ignored: %s', 'grcmanager'),
            htmlescape(implode(', ', array_slice($result['rejected'], 0, 10)))
        ), false, WARNING);
    }
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update([
        'id'      => (int) $_POST['id'],
        'verdict' => (string) ($_POST['verdict'] ?? ''),
        'comment' => (string) ($_POST['comment'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();

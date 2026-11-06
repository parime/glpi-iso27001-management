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

// Issue #377 : dépendance entre processus métier (onglet « Processus liés »). Le lien appartient au
// processus qui dépend de l'autre : l'ajouter ou le retirer exige de pouvoir le modifier (droit et
// entité) ; l'ajouter exige aussi de pouvoir lire le processus amont.
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, UPDATE);
$process = new \GlpiPlugin\Grcmanager\BusinessProcess();
$link = new \GlpiPlugin\Grcmanager\BusinessProcessLink();

if (isset($_POST['add'])) {
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    $upstreamId = (int) ($_POST[\GlpiPlugin\Grcmanager\BusinessProcessLink::UPSTREAM_FIELD] ?? 0);
    // Processus amont illisible (autre entité) : introuvable pour la personne.
    $upstream = new \GlpiPlugin\Grcmanager\BusinessProcess();
    if ($upstreamId > 0 && (!$upstream->getFromDB($upstreamId) || !$upstream->canViewItem())) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $link->add([
        'plugin_grcmanager_businessprocesses_id'                 => (int) $process->getID(),
        \GlpiPlugin\Grcmanager\BusinessProcessLink::UPSTREAM_FIELD => $upstreamId,
        'comment'                                                => (string) ($_POST['comment'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    if (!$link->getFromDB((int) ($_POST['id'] ?? 0))) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $process->check((int) $link->fields['plugin_grcmanager_businessprocesses_id'], UPDATE);
    $link->delete(['id' => (int) $link->getID()], true);
}

Html::back();

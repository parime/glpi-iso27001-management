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

// Issue #145 : enregistre ou retire la date de validité d'un document de preuve, depuis l'onglet
// « Validités des preuves » de l'élément porteur, puis revient sur sa fiche. Droit de modification
// sur CET élément (entité comprise), jamais le seul droit global du plugin.
Session::checkRight(\GlpiPlugin\Grcmanager\Evidence::RIGHTNAME, UPDATE);

$itemtype = (string) ($_POST['itemtype'] ?? '');
$itemsId = (int) ($_POST['items_id'] ?? 0);
$documentsId = (int) ($_POST['documents_id'] ?? 0);

$parent = \GlpiPlugin\Grcmanager\Evidence::loadParent($itemtype, $itemsId);
if ($parent === null) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
if (!$parent->can($itemsId, UPDATE)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
if (!\GlpiPlugin\Grcmanager\Evidence::isDocumentLinked($itemtype, $itemsId, $documentsId)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
// L'élément porteur est global, le document de preuve appartient à une entité.
if (!\GlpiPlugin\Grcmanager\Evidence::canReadDocument($documentsId)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$evidence = new \GlpiPlugin\Grcmanager\Evidence();
$exists = $evidence->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $itemsId, 'documents_id' => $documentsId]);

if (isset($_POST['purge'])) {
    if ($exists) {
        $evidence->delete(['id' => $evidence->getID()], true);
    }
} elseif (isset($_POST['save'])) {
    $input = [
        'valid_until' => (string) ($_POST['valid_until'] ?? ''),
        'users_id'    => (int) ($_POST['users_id'] ?? 0),
    ];
    if ($exists) {
        $evidence->update($input + ['id' => $evidence->getID()]);
    } else {
        $evidence->add($input + ['itemtype' => $itemtype, 'items_id' => $itemsId, 'documents_id' => $documentsId]);
    }
}

Html::redirect($parent::getFormURLWithID($itemsId));

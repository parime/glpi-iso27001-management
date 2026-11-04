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

// Issue #377 : dépendances d'un processus métier (onglet « Dépendances »). Lier ou retirer un élément
// exige de pouvoir modifier le processus (droit et entité) ; lier exige aussi de pouvoir lire
// l'élément (BusinessProcessItem::prepareInputForAdd()).
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, UPDATE);
$process = new \GlpiPlugin\Grcmanager\BusinessProcess();
$link = new \GlpiPlugin\Grcmanager\BusinessProcessItem();

if (isset($_POST['add'])) {
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $itemsId = (int) ($_POST['items_id'] ?? 0);
    // Élément d'un type liable et lisible par la personne (droit et entité), sinon introuvable pour elle.
    if (in_array($itemtype, \GlpiPlugin\Grcmanager\BusinessProcessItem::getItemtypes(), true) && $itemsId > 0) {
        // $itemtype est borné par la liste blanche des types liables juste avant l'instanciation.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
    }
    $link->add([
        'plugin_grcmanager_businessprocesses_id' => (int) $process->getID(),
        'itemtype' => $itemtype,
        'items_id' => $itemsId,
        'comment'  => (string) ($_POST['comment'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    if (!$link->getFromDB((int) ($_POST['id'] ?? 0))) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $process->check((int) $link->fields['plugin_grcmanager_businessprocesses_id'], UPDATE);
    $link->delete(['id' => (int) $link->getID()], true);
}

Html::back();

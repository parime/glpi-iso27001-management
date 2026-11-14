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

use GlpiPlugin\Grcmanager\DataBreach;

// Issue #620 : violation de données personnelles. Chaque action vérifie le droit dédié et l'entité de
// la fiche (check() du cœur) ; le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new DataBreach();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(DataBreach::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->delete($_POST);
    Html::redirect(DataBreach::getSearchURL());
} elseif (isset($_POST['restore'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->restore($_POST);
    Html::redirect(DataBreach::getSearchURL());
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(DataBreach::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(DataBreach::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Html::header(
        DataBreach::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Control::class,
        DataBreach::class
    );

    // Création depuis un incident : proposition préremplie (incident lisible seulement, voir showForm()).
    $item->display([
        'id'                    => $id,
        DataBreach::INCIDENT_FK => $id > 0 ? 0 : (int) ($_GET[DataBreach::INCIDENT_FK] ?? 0),
    ]);

    Html::footer();
}

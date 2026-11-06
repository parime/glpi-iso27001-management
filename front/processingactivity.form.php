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

use GlpiPlugin\Grcmanager\ProcessingActivity;

// Issue #375 : fiche du registre des traitements. Chaque action vérifie le droit dédié et l'entité
// de la fiche (check() du cœur) ; le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new ProcessingActivity();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->delete($_POST);
    Html::redirect(ProcessingActivity::getSearchURL());
} elseif (isset($_POST['restore'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->restore($_POST);
    Html::redirect(ProcessingActivity::getSearchURL());
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(ProcessingActivity::getSearchURL());
} elseif (isset($_POST['link_incident']) || isset($_POST['unlink_incident'])) {
    // Issue #375 : traitements concernés par un incident, reliés depuis l'onglet de l'incident.
    // Contrôles dans setIncidentLink() : modifier l'incident et lire la fiche.
    Session::checkRight(ProcessingActivity::$rightname, READ);
    if (
        !ProcessingActivity::setIncidentLink(
            (int) ($_POST['plugin_grcmanager_processingactivities_id'] ?? 0),
            (int) ($_POST['incidents_id'] ?? 0),
            isset($_POST['link_incident'])
        )
    ) {
        Html::displayRightError();
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(ProcessingActivity::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Html::header(
        ProcessingActivity::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Control::class,
        ProcessingActivity::class
    );

    $item->display(['id' => $id]);

    Html::footer();
}

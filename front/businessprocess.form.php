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

// Issue #377 : fiche d'un processus métier. Chaque action contrôle le droit ET l'entité du processus
// (CommonDBTM::check()), y compris l'entité choisie à la création.
$item = new \GlpiPlugin\Grcmanager\BusinessProcess();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
    Html::redirect(\GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, READ);

    Html::header(
        \GlpiPlugin\Grcmanager\BusinessProcess::getTypeName(1),
        \GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL(),
        'grcmanager',
        \GlpiPlugin\Grcmanager\AccessReview::class,
        \GlpiPlugin\Grcmanager\BusinessProcess::class
    );

    // display() vérifie la lecture de ce processus (droit et entité) avant de l'afficher.
    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);

    Html::footer();
}

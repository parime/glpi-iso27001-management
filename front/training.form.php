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

$item = new PluginGrcmanagerTraining();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerTraining::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerTraining::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerTraining::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerTraining::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerTraining::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerTraining::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerTraining::$rightname, READ);

    // Issue #139 : affichage avec onglets (Documents de la session, Historique). `retake_of`
    // pré-remplit une session de rattrapage, `session_of` une nouvelle session de la formation :
    // rien n'est enregistré avant « Ajouter ».
    PluginGrcmanagerTraining::displayFullPageForItem(
        (int) ($_GET['id'] ?? 0),
        [
            'central'  => ['grcmanager', PluginGrcmanagerAccessReview::class, PluginGrcmanagerTraining::class],
            'helpdesk' => [],
        ],
        [
            'retake_of'  => (int) ($_GET['retake_of'] ?? 0),
            'session_of' => (int) ($_GET['session_of'] ?? 0),
        ]
    );
}

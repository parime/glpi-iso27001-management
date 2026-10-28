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

$item = new \GlpiPlugin\Grcmanager\Training();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\Training::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\Training::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, READ);

    // Issue #139 : affichage avec onglets (Documents de la session, Historique). `retake_of`
    // pré-remplit une session de rattrapage, `session_of` une nouvelle session de la formation :
    // rien n'est enregistré avant « Ajouter ».
    \GlpiPlugin\Grcmanager\Training::displayFullPageForItem(
        (int) ($_GET['id'] ?? 0),
        [
            'central'  => [
                'grcmanager',
                \GlpiPlugin\Grcmanager\AccessReview::class,
                \GlpiPlugin\Grcmanager\Training::class,
            ],
            'helpdesk' => [],
        ],
        [
            'retake_of'  => (int) ($_GET['retake_of'] ?? 0),
            'session_of' => (int) ($_GET['session_of'] ?? 0),
        ]
    );
}

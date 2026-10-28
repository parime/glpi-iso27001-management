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

$item = new \GlpiPlugin\Grcmanager\Control();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\Control::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\Control::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // Issue #126 : displayFullPageForItem() (comme front/policy.form.php) affiche la barre
    // d'onglets (revues des accès, documents) ; showForm() seul ne la rend pas.
    \GlpiPlugin\Grcmanager\Control::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => ['grcmanager', \GlpiPlugin\Grcmanager\Control::class], 'helpdesk' => []]
    );
}

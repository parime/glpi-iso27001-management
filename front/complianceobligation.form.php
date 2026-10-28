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

$item = new \GlpiPlugin\Grcmanager\ComplianceObligation();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\ComplianceObligation::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\ComplianceObligation::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, READ);

    Html::header(
        \GlpiPlugin\Grcmanager\ComplianceObligation::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Control::class,
        \GlpiPlugin\Grcmanager\ComplianceObligation::class
    );

    $id = (int) ($_GET['id'] ?? 0);

    if ($id > 0) {
        $item->getFromDB($id);
    }

    $item->showForm($id);

    Html::footer();
}

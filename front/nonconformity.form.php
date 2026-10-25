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

$item = new PluginGrcmanagerNonconformity();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerNonconformity::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Issue #163 : après création (notamment depuis un plan d'action proposé), ouvrir la fiche créée.
    if ($newID) {
        Html::redirect(PluginGrcmanagerNonconformity::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerNonconformity::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerNonconformity::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerNonconformity::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerNonconformity::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerNonconformity::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerNonconformity::$rightname, READ);

    Html::header(
        PluginGrcmanagerNonconformity::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        PluginGrcmanagerNonconformity::class
    );

    $id = (int) ($_GET['id'] ?? 0);

    if ($id > 0) {
        $item->getFromDB($id);
    }

    $item->showForm($id, [
        'from_incident' => (int) ($_GET['from_incident'] ?? 0),
        'cve'           => (string) ($_GET['cve'] ?? ''),
    ]);

    Html::footer();
}

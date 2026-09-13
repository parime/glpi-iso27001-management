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

$item = new PluginGrcmanagerCanonicalProduct();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, CREATE);
    $item->add($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerCanonicalProduct::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerCanonicalProduct::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, READ);

    Html::header(
        PluginGrcmanagerCanonicalProduct::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        PluginGrcmanagerCanonicalProduct::class
    );

    $id = (int) ($_GET['id'] ?? 0);

    if ($id > 0) {
        $item->getFromDB($id);
    }

    $item->showForm($id);

    Html::footer();
}

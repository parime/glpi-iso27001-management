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

$item = new PluginGrcmanagerWaiver();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerWaiver::$rightname, CREATE);
    $newID = $item->add($_POST);
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerWaiver::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerWaiver::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerWaiver::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerWaiver::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerWaiver::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerWaiver::$rightname, READ);

    Html::header(
        PluginGrcmanagerWaiver::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        PluginGrcmanagerRisk::class,
        PluginGrcmanagerWaiver::class
    );

    $item->display([
        'id'        => (int) ($_GET['id'] ?? 0),
        'link_itemtype' => (string) ($_GET['link_itemtype'] ?? ''),
        'link_items_id' => (int) ($_GET['link_items_id'] ?? 0),
    ]);

    Html::footer();
}

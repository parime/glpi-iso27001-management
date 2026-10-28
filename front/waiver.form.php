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

$item = new \GlpiPlugin\Grcmanager\Waiver();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, CREATE);
    $newID = $item->add($_POST);
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\Waiver::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\Waiver::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, READ);

    Html::header(
        \GlpiPlugin\Grcmanager\Waiver::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        PluginGrcmanagerRisk::class,
        \GlpiPlugin\Grcmanager\Waiver::class
    );

    $item->display([
        'id'        => (int) ($_GET['id'] ?? 0),
        'link_itemtype' => (string) ($_GET['link_itemtype'] ?? ''),
        'link_items_id' => (int) ($_GET['link_items_id'] ?? 0),
    ]);

    Html::footer();
}

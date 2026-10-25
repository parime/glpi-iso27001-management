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

$item = new PluginGrcmanagerPolicy();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerPolicy::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerPolicy::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerPolicy::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerPolicy::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerPolicy::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerPolicy::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // Unlike every other showForm()-only front/*.form.php of this plugin (Risk, Control...),
    // this one goes through GLPI core's own displayFullPageForItem() (same call
    // front/reminder.php makes for the native Reminder itemtype) instead of a bare
    // Html::header()/showForm()/Html::footer() sequence: only display() (called internally by
    // displayFullPageForItem(), see CommonDBTM.php) actually renders the tab bar, so this is the
    // first itemtype in this plugin that NEEDS it, for the native "Documents" tab added by
    // PluginGrcmanagerPolicy::defineTabs() to be reachable at all. Confirmed live: calling
    // showForm() directly (like every sibling front/*.form.php) never shows any tab, regardless
    // of defineTabs(). Rights (READ for an existing item, CREATE for a new one) are checked
    // internally by displayFullPageForItem() itself via $item->can(), no separate
    // Session::checkRight() call needed here.
    PluginGrcmanagerPolicy::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => ['grcmanager', PluginGrcmanagerPolicy::class], 'helpdesk' => []]
    );
}

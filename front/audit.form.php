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

$item = new PluginGrcmanagerAudit();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerAudit::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerAudit::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerAudit::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerAudit::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerAudit::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerAudit::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerAudit::$rightname, READ);

    // Issue #140 : affichage avec onglets (Constats, Documents, Historique).
    PluginGrcmanagerAudit::displayFullPageForItem(
        (int) ($_GET['id'] ?? 0),
        ['central' => ['grcmanager', PluginGrcmanagerAudit::class], 'helpdesk' => []]
    );
}

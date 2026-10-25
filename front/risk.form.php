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

$item = new PluginGrcmanagerRisk();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, CREATE);
    $newID = $item->add($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerRisk::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerRisk::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // Issue #171 : affichage avec onglets (formulaire, « Effet domino »...), comme les risques
    // fournisseurs ; showForm() seul ne rend pas la barre d'onglets. Les droits (READ, ou CREATE
    // pour un nouvel élément) sont vérifiés par $item->can().
    PluginGrcmanagerRisk::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => ['grcmanager', PluginGrcmanagerRisk::class], 'helpdesk' => []]
    );
}

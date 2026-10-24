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

$item = new PluginGrcmanagerComplianceEvent();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, CREATE);
    $newID = $item->add($_POST);
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerComplianceEvent::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, PURGE);
    $item->delete($_POST, 1);
    Html::redirect(PluginGrcmanagerComplianceEvent::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, READ);

    Html::header(
        PluginGrcmanagerComplianceEvent::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        PluginGrcmanagerComplianceEvent::class
    );

    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);

    Html::footer();
}

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

// Issue #616 : modèle de message de crise de la bibliothèque.
$item = new \GlpiPlugin\Grcmanager\CrisisMessageTemplate();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::$rightname, CREATE);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::$rightname, READ);

    Html::header(
        \GlpiPlugin\Grcmanager\CrisisMessageTemplate::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        PluginGrcmanagerSecurityIncident::class
    );

    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);

    Html::footer();
}

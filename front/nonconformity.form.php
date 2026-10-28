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

$item = new \GlpiPlugin\Grcmanager\Nonconformity();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, CREATE);
    $newID = $item->add($_POST);
    // Issue #163 : après création (notamment depuis un plan d'action proposé), ouvrir la fiche créée.
    if ($newID) {
        Html::redirect(\GlpiPlugin\Grcmanager\Nonconformity::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\Nonconformity::getSearchURL());
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\Nonconformity::getSearchURL());
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, READ);

    // Issue #140 : affichage avec onglets (Documents, Historique).
    \GlpiPlugin\Grcmanager\Nonconformity::displayFullPageForItem(
        (int) ($_GET['id'] ?? 0),
        [
            'central'  => [
                'grcmanager',
                \GlpiPlugin\Grcmanager\Audit::class,
                \GlpiPlugin\Grcmanager\Nonconformity::class,
            ],
            'helpdesk' => [],
        ],
        [
            'from_incident' => (int) ($_GET['from_incident'] ?? 0),
            'cve'           => (string) ($_GET['cve'] ?? ''),
            'audit'         => (int) ($_GET['audit'] ?? 0),
        ]
    );
}

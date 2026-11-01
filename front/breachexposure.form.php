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

// Issue #183 : une exposition n'est jamais créée à la main (tâche planifiée) ; le RSSI en change le
// statut et le commentaire (prepareInputForUpdate() ignore tout autre champ), ou la supprime.
$item = new \GlpiPlugin\Grcmanager\BreachExposure();

if (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\BreachExposure::getSearchURL());
} else {
    Session::checkRight(\GlpiPlugin\Grcmanager\BreachExposure::$rightname, READ);

    Html::header(
        \GlpiPlugin\Grcmanager\BreachExposure::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\ComplianceAudit::class,
        \GlpiPlugin\Grcmanager\BreachExposure::class
    );

    $item->display(['id' => (int) ($_GET['id'] ?? 0)]);

    Html::footer();
}

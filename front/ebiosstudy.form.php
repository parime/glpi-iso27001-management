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

use GlpiPlugin\Grcmanager\EbiosStudy;

include('../../../inc/includes.php');

// Issue #376 : fiche d'une étude EBIOS Risk Manager.
$item = new EbiosStudy();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(EbiosStudy::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
}

Session::checkRight(EbiosStudy::$rightname, READ);
Html::header(
    EbiosStudy::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    GlpiPlugin\Grcmanager\Risk::class,
    EbiosStudy::class
);
$item->display(['id' => (int) ($_GET['id'] ?? 0)]);
Html::footer();

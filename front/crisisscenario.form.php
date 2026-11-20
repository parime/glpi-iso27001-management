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

use GlpiPlugin\Grcmanager\CrisisScenario;

// Issue #776 : scénario de crise sécurité de l'information. Chaque action vérifie le droit de la
// continuité et l'entité du scénario (check() du cœur) ; les processus liés sont contrôlés un à un
// (lecture et entité du scénario). Le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new CrisisScenario();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(CrisisScenario::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(CrisisScenario::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(CrisisScenario::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Html::header(
        CrisisScenario::getTypeName(1),
        CrisisScenario::getSearchURL(),
        'grcmanager',
        \GlpiPlugin\Grcmanager\AccessReview::class,
        CrisisScenario::class
    );

    $item->display(['id' => $id]);

    Html::footer();
}

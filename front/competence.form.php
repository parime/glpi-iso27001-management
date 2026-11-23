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

use GlpiPlugin\Grcmanager\Competence;

// Issue #774 : compétence du catalogue et formations qui la donnent. Chaque action vérifie le droit
// du plugin et l'entité de la fiche (check() du cœur) ; le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new Competence();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(Competence::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(Competence::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    if (!Competence::canView()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    \GlpiPlugin\Grcmanager\Menu::header(
        \GlpiPlugin\Grcmanager\IsmsFunction::class,
        Competence::getTypeName(1)
    );

    $item->display(['id' => $id]);

    Html::footer();
}

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

use GlpiPlugin\Grcmanager\RecyfRequirement;

// Issue #599 : fiche d'une mesure ReCyF (NIS2 France). Catalogue fixe : seule la modification existe.
// Droit général du plugin et entité de la fiche (check() du cœur) ; le jeton CSRF est contrôlé par
// GLPI sur tout POST.
$item = new RecyfRequirement();

if (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(RecyfRequirement::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    // Fiche d'une entité non accessible, ou référentiel désactivé : refusée comme le reste du cœur.
    $item->check($id, READ);

    \GlpiPlugin\Grcmanager\Menu::header(
        \GlpiPlugin\Grcmanager\Navigation\Section\RegulationSection::RECYF,
        RecyfRequirement::getTypeName(1)
    );
    $item->display(['id' => $id]);
    Html::footer();
}

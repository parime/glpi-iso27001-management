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

$item = new \GlpiPlugin\Grcmanager\SupplierRisk();

if (isset($_POST['add'])) {
    // Issue #536, lot 3 : droit de création dans l'entité demandée (check), pas le seul droit global.
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    // Issue #536, lot 3 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\SupplierRisk::getSearchURL());
} elseif (isset($_POST['purge'])) {
    // Issue #536, lot 3 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\SupplierRisk::getSearchURL());
} elseif (isset($_POST['update'])) {
    // Issue #536, lot 3 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // Issue #127 : displayFullPageForItem() (comme front/policy.form.php) affiche la barre
    // d'onglets (questionnaire, contrats, documents, historique) ; showForm() seul ne la rend pas.
    // Les droits (READ, ou CREATE pour un nouvel élément) sont vérifiés par $item->can().
    \GlpiPlugin\Grcmanager\SupplierRisk::displayFullPageForItem(
        $_GET['id'] ?? 0,
        [
            'central'  => \GlpiPlugin\Grcmanager\Menu::sectorPath(\GlpiPlugin\Grcmanager\SupplierRisk::class),
            'helpdesk' => [],
        ]
    );
}

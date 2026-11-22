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

$item = new \GlpiPlugin\Grcmanager\Control();

if (isset($_POST['add'])) {
    // Issue #536 : droit de création dans l'entité demandée (check), pas le seul droit global.
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    // Comme les formulaires natifs : retour sur l'élément créé si la préférence est active.
    if ($newID && $_SESSION['glpibackcreated']) {
        Html::redirect($item->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['create_entity_soa'])) {
    // Issue #536 : l'entité active se dote de sa propre SoA (copie des 93 mesures).
    Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, CREATE);
    $entityId = \GlpiPlugin\Grcmanager\EntityPerimeter::activeEntity();
    if (!\GlpiPlugin\Grcmanager\EntityPerimeter::canCreateSoa($entityId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $created = \GlpiPlugin\Grcmanager\EntityPerimeter::createSoa($entityId);
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('This entity now has its own Statement of Applicability (%d controls to review).', 'grcmanager'),
        $created
    )));
    Html::redirect(\GlpiPlugin\Grcmanager\Control::getSearchURL());
} elseif (isset($_POST['delete'])) {
    // Issue #536 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), DELETE);
    $item->delete($_POST);
    Html::redirect(\GlpiPlugin\Grcmanager\Control::getSearchURL());
} elseif (isset($_POST['purge'])) {
    // Issue #536 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete($_POST, true);
    Html::redirect(\GlpiPlugin\Grcmanager\Control::getSearchURL());
} elseif (isset($_POST['update'])) {
    // Issue #536 : droit ET entité de l'élément visé (check), pas le seul droit global.
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    // Issue #126 : displayFullPageForItem() (comme front/policy.form.php) affiche la barre
    // d'onglets (revues des accès, documents) ; showForm() seul ne la rend pas.
    \GlpiPlugin\Grcmanager\Control::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => \GlpiPlugin\Grcmanager\Menu::sectorPath(\GlpiPlugin\Grcmanager\Control::class), 'helpdesk' => []]
    );
}

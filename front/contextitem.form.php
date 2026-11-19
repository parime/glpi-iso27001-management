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

use GlpiPlugin\Grcmanager\ContextItem;

// Issue #608 : enjeu ou partie intéressée. Chaque action vérifie le droit général du plugin et
// l'entité de la fiche (check() du cœur) ; le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new ContextItem();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(ContextItem::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->delete($_POST);
    Html::redirect(ContextItem::getSearchURL());
} elseif (isset($_POST['restore'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->restore($_POST);
    Html::redirect(ContextItem::getSearchURL());
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(ContextItem::getSearchURL());
} elseif (isset($_POST['update']) || isset($_POST[ContextItem::REVIEW_MARKER])) {
    // « Enregistrer la revue » : même contrôle qu'une modification ; la date et l'auteur de la revue
    // sont fixés par la fiche (prepareInputForUpdate), jamais repris de la saisie.
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Session::checkRight(ContextItem::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Html::header(
        ContextItem::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Control::class,
        ContextItem::class
    );

    // Création depuis les boutons de la liste : enjeu, partie intéressée ou question climatique ;
    // depuis l'onglet d'un risque, d'une obligation ou d'une mesure, cet élément est présélectionné
    // (seulement s'il fait partie des choix que la personne peut lire).
    $item->display([
        'id'        => $id,
        'kind'      => (string) ($_GET['kind'] ?? ''),
        'category'  => (string) ($_GET['category'] ?? ''),
        'link_type' => (string) ($_GET['link_type'] ?? ''),
        'link_id'   => (int) ($_GET['link_id'] ?? 0),
    ]);

    Html::footer();
}

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

// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

// Issue #370 : étapes d'une procédure (onglet « Étapes »), avec retour sur la procédure.
$item = new \GlpiPlugin\Grcmanager\PlaybookStep();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Playbook::$rightname, UPDATE);
    $item->add($_POST);
} elseif (isset($_POST['update'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Playbook::$rightname, UPDATE);
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Playbook::$rightname, UPDATE);
    $item->check((int) $_POST['id'], PURGE);
    $item->delete(['id' => (int) $_POST['id']], true);
}

Html::back();

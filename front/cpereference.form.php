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

// Ni menu, ni écran de recherche propres : ce contrôleur ne fait qu'ajouter/supprimer un
// identifiant CPE pour un produit donné puis revenir sur le formulaire de ce produit, même esprit
// que front/objectivemeasurement.form.php.
$canonicalProductId = (int) ($_POST['plugin_grcmanager_canonicalproducts_id'] ?? 0);
$redirectUrl = \GlpiPlugin\Grcmanager\CanonicalProduct::getFormURLWithID($canonicalProductId);

$item = new \GlpiPlugin\Grcmanager\CpeReference();

if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\CanonicalProduct::$rightname, UPDATE);
    $item->add($_POST);
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\CanonicalProduct::$rightname, UPDATE);
    $item->delete($_POST, true);
}

Html::redirect($redirectUrl);

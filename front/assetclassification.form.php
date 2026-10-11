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

use GlpiPlugin\Grcmanager\Services\Risk\RiskItemLinkNormalizer;

include('../../../inc/includes.php');

// Pas de $_GET['id'] classique : ce contrôleur n'a pas de formulaire propre (voir
// PluginGrcmanagerAssetClassification::displayTabContentForItem(), le seul écran qui poste ici),
// il est toujours atteint depuis l'onglet "Classification C/I/D" d'un actif réel, identifié par
// itemtype/items_id (clé composite de ce registre, voir issue #26).
if (!isset($_POST['save'])) {
    Html::back();
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$itemsId  = (int) ($_POST['items_id'] ?? 0);

// Même validation défensive que PluginGrcmanagerRisk::syncLinkedAssets() pour son propre lien
// polymorphe (issue #25) : rejette silencieusement un itemtype/items_id qui ne correspond à aucun
// actif réellement liable par ce plugin, plutôt que de faire confiance à un POST forgé pointant
// vers un itemtype arbitraire (ex. `User`, jamais dans la liste des actifs classifiables).
if (!RiskItemLinkNormalizer::isLinkable($itemtype, $itemsId, PluginGrcmanagerRisk::getLinkableItemtypes())) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$target = new $itemtype();
if (!$target->getFromDB($itemsId)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$existing = PluginGrcmanagerAssetClassification::getByItem($itemtype, $itemsId);

$levels = [
    'confidentiality' => $_POST['confidentiality'] ?? '',
    'integrity'       => $_POST['integrity'] ?? '',
    'availability'    => $_POST['availability'] ?? '',
];

$classification = new PluginGrcmanagerAssetClassification();

if ($existing !== null) {
    Session::checkRight(PluginGrcmanagerAssetClassification::$rightname, UPDATE);
    $classification->update(array_merge(['id' => $existing['id']], $levels));
} else {
    Session::checkRight(PluginGrcmanagerAssetClassification::$rightname, CREATE);
    $classification->add(array_merge(['itemtype' => $itemtype, 'items_id' => $itemsId], $levels));
}

Html::back();

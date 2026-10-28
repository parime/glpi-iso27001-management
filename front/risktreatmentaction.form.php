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

// Ni menu, ni écran de recherche propres : ce contrôleur ne fait qu'ajouter/mettre à jour/supprimer
// une action de traitement pour un risque donné puis revenir sur le formulaire de ce risque, voir
// PluginGrcmanagerRisk::showTreatmentPlan() (même esprit que front/objectivemeasurement.form.php,
// qui redirige lui aussi vers la fiche de l'item porteur plutôt que vers un getSearchURL() qui
// n'aurait pas de sens ici).
$riskId      = (int) ($_POST['plugin_grcmanager_risks_id'] ?? 0);
$redirectUrl = PluginGrcmanagerRisk::getFormURLWithID($riskId);

$item = new PluginGrcmanagerRiskTreatmentAction();

if (isset($_POST['add'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, CREATE);
    $item->add($_POST);
} elseif (isset($_POST['update'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, UPDATE);
    $item->update($_POST);
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, PURGE);
    $item->delete($_POST, true);
}

Html::redirect($redirectUrl);

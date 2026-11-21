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

// Ni menu, ni écran de recherche propres : ce contrôleur ne fait qu'ajouter/supprimer une mesure
// pour un objectif donné puis revenir sur le formulaire de cet objectif, voir
// \GlpiPlugin\Grcmanager\Objective::showMeasurementHistory() (même esprit que
// front/assetclassification.form.php, qui redirige lui aussi vers la fiche de l'item porteur
// plutôt que vers un getSearchURL() qui n'aurait pas de sens ici).
$objectiveId = (int) ($_POST['plugin_grcmanager_objectives_id'] ?? 0);
$redirectUrl = \GlpiPlugin\Grcmanager\Objective::getFormURLWithID($objectiveId);

$item = new \GlpiPlugin\Grcmanager\ObjectiveMeasurement();

// Issue #536, lot 3 : une mesure suit l'objectif, qu'il faut pouvoir modifier (entité comprise).
$objective = new \GlpiPlugin\Grcmanager\Objective();
if (isset($_POST['add'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Objective::$rightname, CREATE);
    $objective->check($objectiveId, UPDATE);
    $item->add($_POST);
} elseif (isset($_POST['purge'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Objective::$rightname, PURGE);
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $objective->check((int) $item->fields['plugin_grcmanager_objectives_id'], UPDATE);
    $item->delete($_POST, true);
}

Html::redirect($redirectUrl);

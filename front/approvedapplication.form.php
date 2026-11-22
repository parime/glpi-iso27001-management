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

use GlpiPlugin\Grcmanager\ApprovedApplication;

// Issue #622 : enregistre la fiche « usage autorisé » d'une application, depuis l'onglet de
// l'application (seul écran qui poste ici). Le droit est vérifié avant toute lecture.
Session::checkRight(ApprovedApplication::$rightname, READ);
if (!isset($_POST['save'])) {
    Html::back();
}

// can() et non getFromDB() : l'application doit être visible par la personne (droit natif ET
// entité), sinon on enregistrerait la fiche d'une application hors de son périmètre.
$appliancesId = (int) ($_POST['appliances_id'] ?? 0);
$appliance = new Appliance();
if ($appliancesId <= 0 || !$appliance->can($appliancesId, READ)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$existing = ApprovedApplication::getForAppliance($appliancesId);
Session::checkRight(ApprovedApplication::$rightname, $existing === null ? CREATE : UPDATE);

$error = ApprovedApplication::saveFor($appliancesId, $_POST);
if ($error !== null) {
    Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
} else {
    Session::addMessageAfterRedirect(htmlescape(__('Approved use sheet saved.', 'grcmanager')));
}

Html::back();

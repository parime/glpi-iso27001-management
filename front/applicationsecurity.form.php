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

use GlpiPlugin\Grcmanager\ApplicationSecurity;

// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

// Issue #777 : enregistre la fiche « développement sécurisé » d'une application, depuis l'onglet
// de l'application (seul écran qui poste ici). Le droit est vérifié avant toute lecture.
Session::checkRight(ApplicationSecurity::$rightname, READ);
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

$existing = ApplicationSecurity::getForAppliance($appliancesId);
Session::checkRight(ApplicationSecurity::$rightname, $existing === null ? CREATE : UPDATE);

$error = ApplicationSecurity::saveFor($appliancesId, $_POST);
if ($error !== null) {
    Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
} else {
    Session::addMessageAfterRedirect(htmlescape(__('Secure development sheet saved.', 'grcmanager')));
}

Html::back();

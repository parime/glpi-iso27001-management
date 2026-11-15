<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

// Issue #142, phase 2 : flux iCal du calendrier de conformité pour un agenda externe, sans
// session. Seule page du plugin ouverte sans session (Firewall::STRATEGY_NO_CHECK, setup.php).
// Issue #664 : elle s'authentifie par le jeton dédié au calendrier (CalendarToken), en lecture
// seule sur ce flux, révocable et à durée limitée ; le jeton personnel GLPI (qui ouvre l'API) n'y
// est plus accepté. Elle charge ensuite les entités et le profil par défaut du propriétaire du
// jeton : le flux ne contient que ce que cet utilisateur voit dans le calendrier.

$usersId = \GlpiPlugin\Grcmanager\CalendarToken::findUser((string) ($_GET['token'] ?? ''));
$user = new User();
if ($usersId === null || !$user->getFromDB($usersId)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
// Session::init() refuse un compte désactivé, supprimé ou hors de ses dates de validité.
Session::init($auth);
if ((int) Session::getLoginUserID() !== $usersId) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$_SESSION['glpidefault_entity'] = $user->fields['entities_id'];
Session::initEntityProfiles($user->getID());
$profiles = $_SESSION['glpiprofiles'] ?? [];
if ($profiles === []) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Session::changeProfile(isset($profiles[$user->fields['profiles_id']]) ? $user->fields['profiles_id'] : key($profiles));
if (!Session::haveRight(\GlpiPlugin\Grcmanager\ComplianceEvent::$rightname, READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendrier-conformite.ics"');
echo \GlpiPlugin\Grcmanager\ComplianceEvent::exportIcs(!empty($_GET['mine']));

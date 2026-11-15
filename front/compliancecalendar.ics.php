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


use GlpiPlugin\Grcmanager\CalendarToken;
use User;

// Issue #142, phase 2 : flux iCal du calendrier de conformité pour un agenda externe, sans
// session. Seule page du plugin ouverte sans session (Firewall::STRATEGY_NO_CHECK, setup.php).
// Issue #XXX : utilise désormais un jeton dédié au calendrier (CalendarToken) au lieu du jeton
// personnel GLPI, pour limiter la portée en cas de fuite de l'URL.

$token = (string) ($_GET['token'] ?? '');
$users_id = CalendarToken::validate($token);
if ($users_id === false) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

// Charger l'utilisateur à partir de l'ID
$user = new User();
if (!$user->getFromDB($users_id)) {
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

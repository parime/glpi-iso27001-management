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
// session. Seule page du plugin ouverte sans session (Firewall::STRATEGY_NO_CHECK, setup.php) :
// elle s'authentifie elle-même par le jeton personnel de l'utilisateur, comme le flux iCal du
// planning de GLPI (front/planning.php?genical=1), puis charge ses entités et son profil par
// défaut : le flux ne contient que ce que cet utilisateur voit dans le calendrier.

include('../../../inc/includes.php');

$token = (string) ($_GET['token'] ?? '');
$user = $token !== '' ? Session::authWithToken($token, 'personal_token', null, null) : false;
if (!$user instanceof User) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$_SESSION['glpidefault_entity'] = $user->fields['entities_id'];
Session::initEntityProfiles($user->getID());
$profiles = $_SESSION['glpiprofiles'] ?? [];
if ($profiles === []) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Session::changeProfile(isset($profiles[$user->fields['profiles_id']]) ? $user->fields['profiles_id'] : key($profiles));
if (!Session::haveRight(PluginGrcmanagerComplianceEvent::$rightname, READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendrier-conformite.ics"');
echo PluginGrcmanagerComplianceEvent::exportIcs(!empty($_GET['mine']));

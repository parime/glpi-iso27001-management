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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditorAccess;

/**
 * Issue #538 : espace de l'auditeur, en lecture seule. Sans identifiant : la liste des audits que la
 * personne connectée peut consulter aujourd'hui (vide pour tout autre compte). Avec un identifiant :
 * cet audit, seulement si son accès est en vigueur. Aucun droit GLPI n'est demandé ni accordé.
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();
$auditId = (int) ($_GET['id'] ?? 0);

$audit = new Audit();
if ($auditId > 0 && (!AuditorAccess::isActive($auditId, $usersId) || !$audit->getFromDB($auditId))) {
    throw new AccessDeniedHttpException();
}

AuditorAccess::header(AuditorAccess::getTypeName(), AuditorAccess::getSpaceURL());
\GlpiPlugin\Grcmanager\PageHelp::show('auditorspace');
if ($auditId > 0) {
    AuditorAccess::touch($auditId, $usersId);
    AuditorAccess::showSpace($audit, $usersId);
} else {
    AuditorAccess::showList($usersId);
}
AuditorAccess::footer();

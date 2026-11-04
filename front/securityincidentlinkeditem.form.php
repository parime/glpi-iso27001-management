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

use GlpiPlugin\Grcmanager\SecurityIncidentLinkedItem;

/**
 * Issue #372 : « Déclarer un incident » depuis l'onglet « Incidents de sécurité » d'un élément
 * (ordinateur, matériel réseau, logiciel, utilisateur, fournisseur, problème, changement).
 */

Session::checkRight(PluginGrcmanagerSecurityIncident::RIGHTNAME, CREATE);

$itemtype = (string) ($_POST['itemtype'] ?? '');
$itemsId = (int) ($_POST['items_id'] ?? 0);
// Seuls les types de l'onglet ; l'élément doit être lu par l'utilisateur.
if (!isset($_POST['declare']) || !in_array($itemtype, SecurityIncidentLinkedItem::ITEMTYPES, true)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$item = getItemForItemtype($itemtype);
if (!$item instanceof CommonDBTM || !$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$incidentsId = SecurityIncidentLinkedItem::declare($item);
if ($incidentsId <= 0) {
    Session::addMessageAfterRedirect(
        htmlescape(__('The security incident could not be created.', 'grcmanager')),
        false,
        ERROR
    );
    Html::back();
}

Session::addMessageAfterRedirect(
    htmlescape(sprintf(__('Security incident no. %d declared.', 'grcmanager'), $incidentsId))
);
Html::redirect(PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentsId));

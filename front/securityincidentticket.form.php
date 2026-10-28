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

/**
 * Issue #128 : « Qualifier en incident de sécurité » depuis l'onglet du ticket.
 */

Session::checkRight(PluginGrcmanagerSecurityIncident::RIGHTNAME, CREATE);

$ticket = new Ticket();
$ticketsId = (int) ($_POST['tickets_id'] ?? 0);
if (!isset($_POST['qualify']) || !$ticket->getFromDB($ticketsId) || !$ticket->can($ticketsId, READ)) {
    Html::back();
}

$incidentsId = PluginGrcmanagerSecurityIncidentTicket::qualify($ticket);
if ($incidentsId <= 0) {
    Session::addMessageAfterRedirect(__('The security incident could not be created.', 'grcmanager'), false, ERROR);
    Html::back();
}

Session::addMessageAfterRedirect(
    sprintf(__('Ticket qualified as security incident no. %d.', 'grcmanager'), $incidentsId)
);
Html::redirect(PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentsId));

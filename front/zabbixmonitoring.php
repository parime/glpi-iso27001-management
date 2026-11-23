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

use GlpiPlugin\Grcmanager\ZabbixMonitoring;

// Issue #836 : supervision lue dans Zabbix (A.8.16, A.8.6, A.5.30). Droit du plugin et lecture native
// des ordinateurs ; actifs des entités de la session seulement. Rien n'est jamais modifié dans Zabbix.
Session::checkRight(ZabbixMonitoring::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

$back = static function (string $anchor): void {
    Html::redirect(ZabbixMonitoring::getSearchURL() . '#' . $anchor);
};

// Issue #1042 : la connexion et les actifs à superviser se règlent dans la configuration (onglet
// Connecteurs). L'ancien envoi de cette page reste accepté pendant une version et y mène.
\GlpiPlugin\Grcmanager\Config\Block\ZabbixConnectorBlock::handleLegacyPost();

if (isset($_POST['read_zabbix'])) {
    Session::checkRight(ZabbixMonitoring::RIGHTNAME, UPDATE);
    $result = ZabbixMonitoring::read();
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(ZabbixMonitoring::errorMessage($result['error'])), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Zabbix read: %1$d host(s), %2$d problem(s), %3$d service(s).', 'grcmanager'),
            $result['hosts'],
            $result['problems'],
            $result['services']
        )), false, $result['hosts'] > 0 ? INFO : WARNING);
    }
    $back('grc-zabbix-connection');
}

if (isset($_POST['clear_zabbix'])) {
    Session::checkRight(ZabbixMonitoring::RIGHTNAME, UPDATE);
    ZabbixMonitoring::clear();
    Session::addMessageAfterRedirect(htmlescape(__('Zabbix readings deleted.', 'grcmanager')));
    $back('grc-zabbix-connection');
}

// Problème de gravité haute proposé, sur clic, comme incident de sécurité ou ticket.
if (isset($_POST['zabbix_incident'])) {
    $incidentId = ZabbixMonitoring::proposeIncident((int) $_POST['zabbix_incident']);
    if ($incidentId > 0) {
        Html::redirect(PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('The security incident could not be created.', 'grcmanager')),
        false,
        ERROR
    );
    $back('grc-zabbix-problems');
}

if (isset($_POST['zabbix_ticket'])) {
    $ticketId = ZabbixMonitoring::createTicket((int) $_POST['zabbix_ticket']);
    if ($ticketId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketId));
    }
    Session::addMessageAfterRedirect(htmlescape(__('The ticket could not be created.', 'grcmanager')), false, ERROR);
    $back('grc-zabbix-problems');
}

// Service Zabbix rattaché à un processus métier (comparaison avec le RTO et la criticité).
if (isset($_POST['link_zabbix_service'])) {
    Session::checkRight(ZabbixMonitoring::RIGHTNAME, UPDATE);
    $ok = ZabbixMonitoring::linkService(
        (int) ($_POST['zabbix_service'] ?? 0),
        (int) ($_POST['zabbix_process'] ?? 0)
    );
    Session::addMessageAfterRedirect(
        htmlescape($ok
            ? __('Business process saved.', 'grcmanager')
            : __('The business process could not be saved.', 'grcmanager')),
        false,
        $ok ? INFO : ERROR
    );
    $back('grc-zabbix-services');
}

\GlpiPlugin\Grcmanager\Menu::header(
    ZabbixMonitoring::class,
    ZabbixMonitoring::getTypeName(),
    ZabbixMonitoring::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(ZabbixMonitoring::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('zabbixmonitoring');

ZabbixMonitoring::showPage();

Html::footer();

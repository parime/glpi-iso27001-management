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

use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;

// Issue #842 : services exposés lus dans Uptime Kuma (A.8.16, A.5.30, A.8.24). Droit du plugin et
// lecture native des ordinateurs ; actifs des entités de la session seulement. Rien n'est jamais
// modifié dans Uptime Kuma.
Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

$back = static function (string $anchor): void {
    Html::redirect(UptimeKumaMonitoring::getSearchURL() . '#' . $anchor);
};

// Issue #1042 : la connexion et les seuils se règlent dans la configuration (onglet Connecteurs).
// L'ancien envoi de cette page reste accepté pendant une version et y mène.
\GlpiPlugin\Grcmanager\Config\Block\UptimeKumaConnectorBlock::handleLegacyPost();

if (isset($_POST['read_uptimekuma'])) {
    Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
    $result = UptimeKumaMonitoring::read();
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(
            htmlescape(UptimeKumaMonitoring::errorMessage($result['error'], $result['page'])),
            false,
            ERROR
        );
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Uptime Kuma read: %1$d monitor(s), %2$d new status reading(s).', 'grcmanager'),
            $result['monitors'],
            $result['samples']
        )), false, $result['monitors'] > 0 ? INFO : WARNING);
    }
    $back('grc-kuma-connection');
}

if (isset($_POST['clear_uptimekuma'])) {
    Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
    UptimeKumaMonitoring::clear();
    Session::addMessageAfterRedirect(htmlescape(__('Uptime Kuma readings deleted.', 'grcmanager')));
    $back('grc-kuma-connection');
}

// Panne longue proposée, sur clic, comme incident de sécurité.
if (isset($_POST['uptimekuma_incident'])) {
    $incidentId = UptimeKumaMonitoring::proposeIncident((int) $_POST['uptimekuma_incident']);
    if ($incidentId > 0) {
        Html::redirect(PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('The security incident could not be created.', 'grcmanager')),
        false,
        ERROR
    );
    $back('grc-kuma-monitors');
}

// Sonde rattachée à un processus métier (comparaison avec le RTO et la criticité).
if (isset($_POST['link_uptimekuma_process'])) {
    Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
    $ok = UptimeKumaMonitoring::linkProcess(
        (int) ($_POST['uptimekuma_monitor'] ?? 0),
        (int) ($_POST['uptimekuma_process'] ?? 0)
    );
    Session::addMessageAfterRedirect(
        htmlescape($ok
            ? __('Business process saved.', 'grcmanager')
            : __('The business process could not be saved.', 'grcmanager')),
        false,
        $ok ? INFO : ERROR
    );
    $back('grc-kuma-monitors');
}

Html::header(
    UptimeKumaMonitoring::getTypeName(),
    UptimeKumaMonitoring::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    UptimeKumaMonitoring::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\ComplianceAudit::class, UptimeKumaMonitoring::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('uptimekuma');

UptimeKumaMonitoring::showPage();

Html::footer();

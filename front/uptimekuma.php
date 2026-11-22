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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;

// Issue #842 : services exposés lus dans Uptime Kuma (A.8.16, A.5.30, A.8.24). Droit du plugin et
// lecture native des ordinateurs ; actifs des entités de la session seulement. Rien n'est jamais
// modifié dans Uptime Kuma.
Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

$back = static function (string $anchor): void {
    Html::redirect(UptimeKumaMonitoring::getSearchURL() . '#' . $anchor);
};

// Connexion : adresse, clé d'API (chiffrée, jamais réaffichée), pages de statut, entité, tâche.
if (isset($_POST['save_uptimekuma'])) {
    Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
    $entity = (int) ($_POST['uptimekuma_entities_id'] ?? UptimeKumaConfig::entity());
    if (!Session::haveAccessToEntity($entity)) {
        $entity = (int) Session::getActiveEntity();
    }
    $saved = UptimeKumaConfig::save(
        (string) ($_POST['uptimekuma_url'] ?? ''),
        (string) ($_POST['uptimekuma_api_key'] ?? ''),
        !empty($_POST['uptimekuma_clear_key']),
        (string) ($_POST['uptimekuma_status_pages'] ?? ''),
        $entity,
        !empty($_POST['uptimekuma_enabled'])
    );
    if (!$saved['saved']) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Not saved: the address must start with https:// (or http:// when internal addresses are allowed), '
                . 'without user name, password or parameters.',
            'grcmanager'
        )), false, ERROR);
    } else {
        if ($saved['changes'] !== []) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $saved['changes']]);
        }
        UptimeKumaMonitoring::syncCronState(UptimeKumaConfig::isEnabled());
        Session::addMessageAfterRedirect(htmlescape(__('Uptime Kuma settings saved.', 'grcmanager')));
    }
    $back('grc-kuma-connection');
}

// Période, seuil des certificats, durée d'une panne longue.
if (isset($_POST['save_uptimekuma_settings'])) {
    Session::checkRight(UptimeKumaMonitoring::RIGHTNAME, UPDATE);
    $before = UptimeKumaConfig::settings();
    $after = UptimeKumaConfig::saveSettings([
        'period_days'    => $_POST['uptimekuma_period_days'] ?? '',
        'cert_days'      => $_POST['uptimekuma_cert_days'] ?? '',
        'outage_minutes' => $_POST['uptimekuma_outage_minutes'] ?? '',
    ]);
    if ($after !== $before) {
        AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => ['uptimekuma_settings' => $after]]);
    }
    UptimeKumaMonitoring::reset();
    Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
    $back('grc-kuma-settings');
}

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

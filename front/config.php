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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitorConfig;
use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Cve\NvdConfig;
use GlpiPlugin\Grcmanager\Services\GithubVersionChecker;
use GlpiPlugin\Grcmanager\IncidentSecurityGroup;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;

Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, READ);

if (isset($_POST['update_risk_matrix'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $probabilities = array_keys(\GlpiPlugin\Grcmanager\Risk::getProbabilities());
    $impacts       = array_keys(\GlpiPlugin\Grcmanager\Risk::getImpacts());

    $matrix = [];
    foreach ($probabilities as $probability) {
        foreach ($impacts as $impact) {
            $value = $_POST['matrix_' . $probability . '_' . $impact] ?? 'medium';
            // Closed enumeration, never trusted as free text from the request.
            $matrix[$probability][$impact] = in_array($value, $impacts, true) ? $value : 'medium';
        }
    }

    RiskMatrixConfig::save($matrix);

    Html::back();
}

if (isset($_POST['update_securityincident_modules'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $modules = [
        'securityincident_enabled'           => isset($_POST['securityincident_enabled']),
        'securityincident_cve_enabled'       => isset($_POST['securityincident_cve_enabled']),
        'securityincident_templates_enabled' => isset($_POST['securityincident_templates_enabled']),
        'securityincident_dashboard_enabled' => isset($_POST['securityincident_dashboard_enabled']),
    ];
    $before = SecurityIncidentModuleConfig::load() + ['security_groups_id' => IncidentSecurityGroup::groupId()];
    SecurityIncidentModuleConfig::save($modules);
    // Issue #371 : groupe sécurité optionnel.
    IncidentSecurityGroup::save((int) ($_POST['security_groups_id'] ?? 0));
    $after = $modules + ['security_groups_id' => IncidentSecurityGroup::groupId()];
    $changes = array_diff_assoc(array_map('intval', $after), array_map('intval', $before));
    if ($changes !== []) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $changes]);
    }
    // Issue #371 : module désactivé, alerte immédiate au groupe RSSI.
    if (!empty($before['securityincident_enabled']) && !$modules['securityincident_enabled']) {
        \GlpiPlugin\Grcmanager\IncidentAccessWatch::moduleDisabled();
    }

    Html::back();
}

// Issue #140 : groupe RSSI, audience des documents de tous les audits.
// Registres sensibles : contenu des notifications (risques, risques fournisseurs, actions de
// traitement). Réglage réservé à qui gère ces registres.
if (isset($_POST['update_sensitive_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::SENSITIVE_RIGHT, UPDATE);

    SensitiveNotification::setMinimal(!empty($_POST['notifications_minimal']));

    Html::back();
}

// Issue #183 : surveillance des fuites de données (Have I Been Pwned). Registre sensible : réglage
// réservé à qui gère le plugin ET les registres sensibles. La clé n'est jamais renvoyée au navigateur.
if (isset($_POST['update_breach_config']) || isset($_POST['test_breach_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::SENSITIVE_RIGHT, UPDATE);

    if (isset($_POST['update_breach_config'])) {
        BreachMonitorConfig::save(
            !empty($_POST['hibp_enabled']),
            (string) ($_POST['hibp_domains'] ?? ''),
            (string) ($_POST['hibp_api_key'] ?? ''),
            !empty($_POST['hibp_clear_key'])
        );
    } else {
        $client = new HibpClient(BreachMonitorConfig::apiKey());
        $response = $client->subscribedDomains();
        // Offre sans recherche par domaine : 401 même avec une clé valide. La clé est alors éprouvée
        // sur un compte de test publié par Have I Been Pwned (aucune adresse réelle n'est envoyée).
        $verified = $response->isOk() ? (array) $response->data : [];
        if (!$response->isOk()) {
            $response = $client->breachedAccount(HibpClient::TEST_ACCOUNT);
        }
        if ($response->isOk()) {
            $lines = [];
            foreach (BreachMonitorConfig::domains() as $domain) {
                $lines[] = in_array($domain, $verified, true)
                    ? sprintf(__('%s: verified domain, one request per run', 'grcmanager'), $domain)
                    : sprintf(__('%s: checked address by address', 'grcmanager'), $domain);
            }
            Session::addMessageAfterRedirect(htmlescape(
                __('Have I Been Pwned key accepted.', 'grcmanager') . ' ' . implode(' ; ', $lines)
            ));
        } else {
            $message = $response->isUnauthorized()
                ? __('Have I Been Pwned refused the API key.', 'grcmanager')
                : sprintf(__('Have I Been Pwned could not be reached (code %d).', 'grcmanager'), $response->status);
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        }
    }

    Html::back();
}

// Issue #403 : référentiels de conformité actifs. Rien n'est supprimé : un référentiel désactivé
// est seulement masqué et ignoré des calculs, ses données reviennent à la réactivation.
if (isset($_POST['update_frameworks'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $before = FrameworkConfig::enabled();
    FrameworkConfig::save((array) ($_POST['frameworks'] ?? []));
    $after = FrameworkConfig::enabled();
    if ($before !== $after) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => [
            'frameworks_enabled'  => array_values(array_diff($after, $before)),
            'frameworks_disabled' => array_values(array_diff($before, $after)),
        ]]);
    }

    Html::back();
}

// Issue #375 : contact du DPO, repris sur l'export du registre des traitements (article 30).
if (isset($_POST['update_dpo_contact'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $before = \GlpiPlugin\Grcmanager\ProcessingActivity::dpoContact();
    \GlpiPlugin\Grcmanager\ProcessingActivity::saveDpoContact((string) ($_POST['dpo_contact'] ?? ''));
    if ($before !== \GlpiPlugin\Grcmanager\ProcessingActivity::dpoContact()) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['dpo_contact' => true],
        ]);
    }

    Html::back();
}

// Issue #380 : groupe RSSI prévenu des tests d'efficacité en retard ou proches de l'échéance.
if (isset($_POST['update_controltest_reminder'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Énumération fermée : une valeur inconnue revient au réglage par défaut.
    $cisoMode = (string) ($_POST['controltest_reminder_ciso'] ?? '');
    if (\GlpiPlugin\Grcmanager\ControlTestReminder::saveCisoMode($cisoMode)) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['controltest_reminder_ciso' => \GlpiPlugin\Grcmanager\ControlTestReminder::cisoMode()],
        ]);
    }

    Html::back();
}

// Issue #378 : niveau d'alerte des indicateurs de risque (rouge seulement, ou dès l'orange).
if (isset($_POST['update_kri_alert'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Énumération fermée : une valeur inconnue revient au réglage par défaut.
    if (\GlpiPlugin\Grcmanager\KriAlert::saveLevel((string) ($_POST['kri_alert_level'] ?? ''))) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['kri_alert_level' => \GlpiPlugin\Grcmanager\KriAlert::level()],
        ]);
    }

    Html::back();
}

if (isset($_POST['update_audit_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId((int) ($_POST['ciso_groups_id'] ?? 0));

    Html::back();
}

if (isset($_POST['update_nvd_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    NvdConfig::save([
        'enable_nvd_enrichment' => isset($_POST['enable_nvd_enrichment']),
        // Never trusted as free text: a non-numeric or out-of-range submission falls back to the
        // documented default rather than storing something a Twig template would then have to
        // guard against when formatting/comparing it.
        'cvss_alert_threshold'  => is_numeric($_POST['cvss_alert_threshold'] ?? null)
            && (float) $_POST['cvss_alert_threshold'] >= 0.0
            && (float) $_POST['cvss_alert_threshold'] <= 10.0
                ? (float) $_POST['cvss_alert_threshold']
                : \GlpiPlugin\Grcmanager\Services\Cve\NvdConfigDefaults::CVSS_ALERT_THRESHOLD,
    ]);
    // Issue #160, phase 2 : seuils des tickets automatiques des vulnérabilités du parc.
    \GlpiPlugin\Grcmanager\ParkVulnerability::saveTicketThresholds($_POST);
    // Issue #485 : rapport des vulnérabilités du parc par e-mail.
    \GlpiPlugin\Grcmanager\VulnerabilityDigest::saveSettings($_POST);

    Html::back();
}

// Issue #485 : envoi immédiat du rapport des vulnérabilités, pour l'essayer sans attendre la tâche.
if (isset($_POST['send_vulnerability_digest'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    if (!\GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync::isEnabled()) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Enable the NVD enrichment first: without it, the report would wrongly say that nothing is exposed.',
            'grcmanager'
        )), false, ERROR);
    } elseif (\GlpiPlugin\Grcmanager\VulnerabilityDigest::send() > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The report has been queued for sending to its recipients.',
            'grcmanager'
        )));
    } else {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The report could not be sent: check that notifications are enabled in GLPI and that the '
                . 'notification "GRC Manager - Fleet vulnerability report" is active.',
            'grcmanager'
        )), false, ERROR);
    }

    Html::back();
}

Html::header(
    __('Configuration', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Grcmanager\Risk::class
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/config_form.html.twig', [
    'risk_matrix'        => RiskMatrixConfig::load(),
    'risk_probabilities' => \GlpiPlugin\Grcmanager\Risk::getProbabilities(),
    'risk_impacts'       => \GlpiPlugin\Grcmanager\Risk::getImpacts(),
    'csrf_token'         => Session::getNewCSRFToken(),
    'installed_version'     => PLUGIN_GRCMANAGER_VERSION,
    'latest_github_version' => GithubVersionChecker::getLatestGithubVersion(),
    'securityincident_modules' => SecurityIncidentModuleConfig::load(),
    'security_group_rand'      => $securityGroupRand = mt_rand(),
    'security_group_dropdown'  => Group::dropdown([
        'name'    => 'security_groups_id',
        'value'   => IncidentSecurityGroup::groupId(),
        'entity'  => 0,
        'entity_sons' => true,
        'rand'    => $securityGroupRand,
        'display' => false,
        'width'   => '100%',
    ]),
    'nvd_config'                => NvdConfig::load(),
    'park_thresholds'           => \GlpiPlugin\Grcmanager\ParkVulnerability::getTicketThresholds(),
    'digest'                    => \GlpiPlugin\Grcmanager\VulnerabilityDigest::configData(),
    'max_tickets'               => \GlpiPlugin\Grcmanager\ParkVulnerability::MAX_TICKETS_PER_RUN,
    'ciso_groups_id'            => \GlpiPlugin\Grcmanager\AuditTeam::getCisoGroupId(),
    'controltest_ciso_mode'     => \GlpiPlugin\Grcmanager\ControlTestReminder::cisoMode(),
    'controltest_ciso_modes'    => \GlpiPlugin\Grcmanager\ControlTestReminder::getCisoModes(),
    'controltest_brand_color'   => \GlpiPlugin\Grcmanager\ControlTestReminder::brandColor(),
    'controltest_cga_branding'  => \GlpiPlugin\Grcmanager\ControlTestReminder::isCgaActive(),
    'kri_alert_level'           => \GlpiPlugin\Grcmanager\KriAlert::level(),
    'kri_alert_levels'          => \GlpiPlugin\Grcmanager\KriAlert::getLevels(),
    'notifications_minimal'     => SensitiveNotification::isMinimal(),
    'can_edit_sensitive'        => Session::haveRight(Installer::SENSITIVE_RIGHT, UPDATE),
    'breach_enabled'            => BreachMonitorConfig::isEnabled(),
    'breach_has_key'            => BreachMonitorConfig::hasApiKey(),
    'breach_domains'            => implode(', ', BreachMonitorConfig::domains()),
    'frameworks'                => FrameworkSelection::FRAMEWORKS,
    'frameworks_help'           => FrameworkConfig::helpTexts(),
    'frameworks_enabled'        => FrameworkConfig::enabled(),
    'dpo_contact'               => \GlpiPlugin\Grcmanager\ProcessingActivity::dpoContact(),
    'can_edit_config'           => Session::haveRight(Installer::RIGHT_NAME, UPDATE),
]);

Html::footer();

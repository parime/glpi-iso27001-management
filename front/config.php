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
use GlpiPlugin\Grcmanager\Services\Cve\NvdConfig;
use GlpiPlugin\Grcmanager\Services\GithubVersionChecker;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;

include('../../../inc/includes.php');

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

    SecurityIncidentModuleConfig::save([
        'securityincident_enabled'           => isset($_POST['securityincident_enabled']),
        'securityincident_cve_enabled'       => isset($_POST['securityincident_cve_enabled']),
        'securityincident_templates_enabled' => isset($_POST['securityincident_templates_enabled']),
        'securityincident_dashboard_enabled' => isset($_POST['securityincident_dashboard_enabled']),
    ]);

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
    PluginGrcmanagerParkVulnerability::saveTicketThresholds($_POST);

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
    'nvd_config'                => NvdConfig::load(),
    'park_thresholds'           => PluginGrcmanagerParkVulnerability::getTicketThresholds(),
    'max_tickets'               => PluginGrcmanagerParkVulnerability::MAX_TICKETS_PER_RUN,
    'ciso_groups_id'            => \GlpiPlugin\Grcmanager\AuditTeam::getCisoGroupId(),
    'notifications_minimal'     => SensitiveNotification::isMinimal(),
    'can_edit_sensitive'        => Session::haveRight(Installer::SENSITIVE_RIGHT, UPDATE),
]);

Html::footer();

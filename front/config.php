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

use GlpiPlugin\Grcmanager\Services\GithubVersionChecker;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;

include('../../../inc/includes.php');

Session::checkRight(PluginGrcmanagerRisk::$rightname, READ);

if (isset($_POST['update_risk_matrix'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, UPDATE);

    $probabilities = array_keys(PluginGrcmanagerRisk::getProbabilities());
    $impacts       = array_keys(PluginGrcmanagerRisk::getImpacts());

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
    Session::checkRight(PluginGrcmanagerRisk::$rightname, UPDATE);

    SecurityIncidentModuleConfig::save([
        'securityincident_enabled'           => isset($_POST['securityincident_enabled']),
        'securityincident_cve_enabled'       => isset($_POST['securityincident_cve_enabled']),
        'securityincident_templates_enabled' => isset($_POST['securityincident_templates_enabled']),
        'securityincident_dashboard_enabled' => isset($_POST['securityincident_dashboard_enabled']),
    ]);

    Html::back();
}

Html::header(
    __('Configuration', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    PluginGrcmanagerRisk::class
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/config_form.html.twig', [
    'risk_matrix'        => RiskMatrixConfig::load(),
    'risk_probabilities' => PluginGrcmanagerRisk::getProbabilities(),
    'risk_impacts'       => PluginGrcmanagerRisk::getImpacts(),
    'csrf_token'         => Session::getNewCSRFToken(),
    'installed_version'     => PLUGIN_GRCMANAGER_VERSION,
    'latest_github_version' => GithubVersionChecker::getLatestGithubVersion(),
    'securityincident_modules' => SecurityIncidentModuleConfig::load(),
]);

Html::footer();

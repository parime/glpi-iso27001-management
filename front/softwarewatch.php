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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

include('../../../inc/includes.php');

global $CFG_GLPI;

// Issue #160 : logiciels à surveiller (droit du plugin + lecture native des logiciels).
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);

$filter = (string) ($_REQUEST['filter'] ?? 'todo');
$search = trim((string) ($_REQUEST['search'] ?? ''));
$back = \GlpiPlugin\Grcmanager\ParkVulnerability::getWatchURL() . '?'
    . http_build_query(['filter' => $filter, 'search' => $search]);
$name = trim((string) ($_POST['software'] ?? ''));

if (isset($_POST['confirm'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, UPDATE);
    $cpe = trim((string) ($_POST['cpe_manual'] ?? '')) ?: (string) ($_POST['cpe'] ?? '');
    $canonicalId = \GlpiPlugin\Grcmanager\ParkVulnerability::confirm($name, $cpe);
    if ($canonicalId === null) {
        $message = __('Pick a suggestion or enter a valid CPE 2.3 (cpe:2.3:a:vendor:product).', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect($back . '&suggest=' . rawurlencode($name));
    }
    $message = sprintf(__('"%s" is now watched.', 'grcmanager'), $name);
    if (\GlpiPlugin\Grcmanager\ParkVulnerability::checkFirstVersion($canonicalId)) {
        $message .= ' ' . __(
            'Its most installed version has just been checked; the automatic action will check the others.',
            'grcmanager'
        );
    }
    Session::addMessageAfterRedirect(htmlescape($message));
    Html::redirect($back);
}

if (isset($_POST['ignore']) || isset($_POST['unignore'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, UPDATE);
    \GlpiPlugin\Grcmanager\ParkVulnerability::setIgnored($name, isset($_POST['ignore']));
    Html::redirect($back);
}

$suggestFor = trim((string) ($_GET['suggest'] ?? ''));
$suggestions = [];
$suggestError = false;
if ($suggestFor !== '' && ParkVulnerabilitySync::isEnabled()) {
    $found = ParkVulnerabilitySync::suggestCpes($suggestFor);
    $suggestError = $found === null;
    $suggestions = $found ?? [];
}

Html::header(
    __('Software to watch', 'grcmanager'),
    \GlpiPlugin\Grcmanager\ParkVulnerability::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    PluginGrcmanagerMenu::SOFTWARE_WATCH
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\ComplianceAudit::class, PluginGrcmanagerMenu::SOFTWARE_WATCH);
PluginGrcmanagerPageHelp::show('softwarewatch');

TemplateRenderer::getInstance()->display('@grcmanager/software_watch.html.twig', [
    'action'        => \GlpiPlugin\Grcmanager\ParkVulnerability::getWatchURL(),
    'park_url'      => \GlpiPlugin\Grcmanager\ParkVulnerability::getSearchURL(),
    'config_url'    => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php',
    'catalog_url'   => \GlpiPlugin\Grcmanager\CanonicalProduct::getFormURL(),
    'rows'          => \GlpiPlugin\Grcmanager\ParkVulnerability::getSoftwareRows($filter, $search),
    'filters'       => \GlpiPlugin\Grcmanager\ParkVulnerability::getFilterLabels(),
    'filter'        => in_array($filter, \GlpiPlugin\Grcmanager\ParkVulnerability::FILTERS, true) ? $filter : 'todo',
    'search'        => $search,
    'suggest_for'   => $suggestFor,
    'suggestions'   => $suggestions,
    'suggest_error' => $suggestError,
    'enabled'       => ParkVulnerabilitySync::isEnabled(),
    'can_update'    => Session::haveRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, UPDATE),
]);

Html::footer();

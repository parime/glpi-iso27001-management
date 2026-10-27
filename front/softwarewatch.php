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
Session::checkRight(PluginGrcmanagerParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);

$filter = (string) ($_REQUEST['filter'] ?? 'todo');
$search = trim((string) ($_REQUEST['search'] ?? ''));
$back = PluginGrcmanagerParkVulnerability::getWatchURL() . '?'
    . http_build_query(['filter' => $filter, 'search' => $search]);
$name = trim((string) ($_POST['software'] ?? ''));

if (isset($_POST['confirm'])) {
    Session::checkRight(PluginGrcmanagerParkVulnerability::RIGHTNAME, UPDATE);
    $cpe = trim((string) ($_POST['cpe_manual'] ?? '')) ?: (string) ($_POST['cpe'] ?? '');
    $canonicalId = PluginGrcmanagerParkVulnerability::confirm($name, $cpe);
    if ($canonicalId === null) {
        $message = __(
            'Choisissez une suggestion ou saisissez un CPE 2.3 valide (cpe:2.3:a:éditeur:produit).',
            'grcmanager'
        );
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect($back . '&suggest=' . rawurlencode($name));
    }
    $message = sprintf(__('« %s » est maintenant surveillé.', 'grcmanager'), $name);
    if (PluginGrcmanagerParkVulnerability::checkFirstVersion($canonicalId)) {
        $message .= ' ' . __(
            'Sa version la plus installée vient d\'être vérifiée ; les autres le seront par la tâche planifiée.',
            'grcmanager'
        );
    }
    Session::addMessageAfterRedirect(htmlescape($message));
    Html::redirect($back);
}

if (isset($_POST['ignore']) || isset($_POST['unignore'])) {
    Session::checkRight(PluginGrcmanagerParkVulnerability::RIGHTNAME, UPDATE);
    PluginGrcmanagerParkVulnerability::setIgnored($name, isset($_POST['ignore']));
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
    __('Logiciels à surveiller', 'grcmanager'),
    PluginGrcmanagerParkVulnerability::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerComplianceAudit::class,
    PluginGrcmanagerMenu::SOFTWARE_WATCH
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerComplianceAudit::class, PluginGrcmanagerMenu::SOFTWARE_WATCH);
PluginGrcmanagerPageHelp::show('softwarewatch');

TemplateRenderer::getInstance()->display('@grcmanager/software_watch.html.twig', [
    'action'        => PluginGrcmanagerParkVulnerability::getWatchURL(),
    'park_url'      => PluginGrcmanagerParkVulnerability::getSearchURL(),
    'config_url'    => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php',
    'catalog_url'   => PluginGrcmanagerCanonicalProduct::getFormURL(),
    'rows'          => PluginGrcmanagerParkVulnerability::getSoftwareRows($filter, $search),
    'filters'       => PluginGrcmanagerParkVulnerability::getFilterLabels(),
    'filter'        => in_array($filter, PluginGrcmanagerParkVulnerability::FILTERS, true) ? $filter : 'todo',
    'search'        => $search,
    'suggest_for'   => $suggestFor,
    'suggestions'   => $suggestions,
    'suggest_error' => $suggestError,
    'enabled'       => ParkVulnerabilitySync::isEnabled(),
    'can_update'    => Session::haveRight(PluginGrcmanagerParkVulnerability::RIGHTNAME, UPDATE),
]);

Html::footer();

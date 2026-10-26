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
use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

Html::header(
    PluginGrcmanagerControl::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerControl::class, PluginGrcmanagerControl::class);
PluginGrcmanagerPageHelp::show('control');

// Intro, exports et rappels d'approbation de la SoA (issue #112).
global $CFG_GLPI;
$front = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/';
$myPendingVersions = PluginGrcmanagerSoaVersion::pendingForUser((int) Session::getLoginUserID());
$lastApprovedVersion = PluginGrcmanagerSoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
$changedSince = $lastApprovedVersion !== null
    && SoaApprovalLogic::currentState(
        (string) $lastApprovedVersion['fingerprint'],
        PluginGrcmanagerSoaVersion::currentFingerprint()
    ) === 'changed';
TemplateRenderer::getInstance()->display('@grcmanager/control_list_header.html.twig', [
    'report_url'      => $front . 'control.report.php',
    'csv_url'         => $front . 'control.csv.php',
    'approval_url'    => $front . 'soaapproval.php',
    'pending_version' => $myPendingVersions !== [] ? (int) $myPendingVersions[0]['version'] : 0,
    'changed_since'   => $changedSince ? (int) $lastApprovedVersion['version'] : 0,
]);

// Same URL-driven search fix as front/risk.php (Search::showList()'s $params must be pre-merged
// with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does this internally),
// see that file's own docblock for the full rationale.
$params = QueryBuilder::manageParams(PluginGrcmanagerControl::class, $_GET);

Search::showList(
    PluginGrcmanagerControl::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerControl::class]
);

Html::footer();

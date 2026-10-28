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

Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Control::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Control::class, \GlpiPlugin\Grcmanager\Control::class);
PluginGrcmanagerPageHelp::show('control');

// Intro, exports et rappels d'approbation de la SoA (issue #112).
global $CFG_GLPI;
$front = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/';
$myPendingVersions = \GlpiPlugin\Grcmanager\SoaVersion::pendingForUser((int) Session::getLoginUserID());
$lastApprovedVersion = \GlpiPlugin\Grcmanager\SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
$changedSince = $lastApprovedVersion !== null
    && SoaApprovalLogic::currentState(
        (string) $lastApprovedVersion['fingerprint'],
        \GlpiPlugin\Grcmanager\SoaVersion::currentFingerprint()
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
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Control::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Control::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Control::class]
);

Html::footer();

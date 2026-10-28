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
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

// Les pages front sont incluses depuis une méthode du routeur GLPI : $CFG_GLPI n'y est
// visible qu'après une déclaration globale explicite.
global $CFG_GLPI;

Session::checkRight(\GlpiPlugin\Grcmanager\Audit::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Audit::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Audit::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Audit::class, \GlpiPlugin\Grcmanager\Audit::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('audit');

// ROADMAP.md "Version 1.5" (rapports exportables pour audit externe) : export PDF mis en forme
// (audits + non-conformités/CAPA), en complément de l'export CSV/XLSX déjà natif de
// Search::showList() ci-dessous.
$auditReportURL = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/audit.report.php';
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => $auditReportURL,
        'icon'  => 'ti-file-type-pdf',
        'label' => __('Export PDF', 'grcmanager'),
        'new_tab' => true,
    ]],
]);

// Same URL-driven search fix as front/risk.php/front/control.php (Search::showList()'s $params
// must be pre-merged with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does
// this internally), see front/risk.php's own docblock for the full rationale.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Audit::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Audit::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Audit::class]
);

Html::footer();

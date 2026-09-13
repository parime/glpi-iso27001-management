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

use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

Session::checkRight(PluginGrcmanagerAudit::$rightname, READ);

Html::header(
    PluginGrcmanagerAudit::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerAudit::class
);

// ROADMAP.md "Version 1.5" (rapports exportables pour audit externe) : export PDF mis en forme
// (audits + non-conformités/CAPA), en complément de l'export CSV/XLSX déjà natif de
// Search::showList() ci-dessous.
$auditReportURL = Plugin::getWebDir('grcmanager') . '/front/audit.report.php';
echo '<div class="d-flex justify-content-end mb-2">';
echo '<a href="' . htmlescape($auditReportURL) . '" class="btn btn-outline-secondary btn-sm" target="_blank">';
echo '<i class="ti ti-file-type-pdf me-1"></i>' . __('Export PDF', 'grcmanager');
echo '</a></div>';

// Same URL-driven search fix as front/risk.php/front/control.php (Search::showList()'s $params
// must be pre-merged with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does
// this internally), see front/risk.php's own docblock for the full rationale.
$params = QueryBuilder::manageParams(PluginGrcmanagerAudit::class, $_GET);

Search::showList(
    PluginGrcmanagerAudit::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerAudit::class]
);

Html::footer();

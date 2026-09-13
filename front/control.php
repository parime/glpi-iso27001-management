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

Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

Html::header(
    PluginGrcmanagerControl::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class
);

// Self-explanatory intro for a non-technical reader landing on a bare "Contrôles Annexe A" list:
// names the ISO clause and the SoA acronym once, up front, rather than nowhere at all.
echo '<div class="alert alert-info d-flex align-items-center justify-content-between mb-2">';
echo '<div><i class="ti ti-info-circle me-2"></i>';
echo __(
    'Déclaration d\'applicabilité (SoA) - clause 6.1.3 ISO/IEC 27001:2022, Annexe A (93 mesures).',
    'grcmanager'
);
echo '</div>';
// ROADMAP.md "Version 1.5" (rapports exportables pour audit externe) : export PDF mis en forme
// (résumé + détail), en complément de l'export CSV/XLSX déjà natif de Search::showList() ci-dessous
// (menu "Exporter" au-dessus de la liste, aucun code plugin nécessaire pour celui-ci).
global $CFG_GLPI;
echo '<a href="' . htmlescape($CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/control.report.php') . '" '
    . 'class="btn btn-outline-secondary btn-sm" target="_blank">';
echo '<i class="ti ti-file-type-pdf me-1"></i>' . __('Export PDF', 'grcmanager') . '</a>';
echo '</div>';

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

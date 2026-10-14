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

Session::checkRight(PluginGrcmanagerIsmsRequirement::$rightname, READ);

Html::header(
    PluginGrcmanagerIsmsRequirement::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerIsmsRequirement::class
);

// Issue #113 : en-tête explicatif + barre de complétude du SMSI (articles 4 à 10), au-dessus de la
// liste standard. Une seule barre empilée : conforme / en cours / non commencé.
$completion = PluginGrcmanagerIsmsRequirement::getCompletion();
$total      = max(1, $completion['total']);

echo '<div class="card mb-2"><div class="card-body">';
echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div><i class="ti ti-info-circle me-2"></i>' . __(
    'Exigences du système de management - articles 4 à 10 ISO/IEC 27001:2022 (références et titres courts uniquement).',
    'grcmanager'
) . '</div>';
global $CFG_GLPI;
echo '<a href="' . htmlescape($CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/control.report.php') . '" '
    . 'class="btn btn-outline-secondary btn-sm" target="_blank">';
echo '<i class="ti ti-file-type-pdf me-1"></i>' . __('Export PDF', 'grcmanager') . '</a>';
echo '</div>';

echo '<div class="mb-1"><strong>' . htmlescape(sprintf(
    __('Complétude du SMSI : %1$d %% (%2$d / %3$d exigences conformes)', 'grcmanager'),
    $completion['percent'],
    $completion['compliant'],
    $completion['total']
)) . '</strong></div>';
echo '<div class="progress progress-separated mb-2" style="height: 12px">';
$barColors = ['compliant' => 'bg-green', 'in_progress' => 'bg-blue', 'not_started' => 'bg-secondary'];
foreach ($barColors as $status => $color) {
    $width = round(100 * $completion[$status] / $total, 1);
    echo '<div class="progress-bar ' . $color . '" role="progressbar" style="width: ' . $width . '%"></div>';
}
echo '</div>';
foreach (array_keys(PluginGrcmanagerIsmsRequirement::getStatuses()) as $status) {
    echo PluginGrcmanagerIsmsRequirement::statusBadge($status)
        . ' <span class="me-3">' . $completion[$status] . '</span>';
}
echo '</div></div>';

$params = QueryBuilder::manageParams(PluginGrcmanagerIsmsRequirement::class, $_GET);

Search::showList(
    PluginGrcmanagerIsmsRequirement::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerIsmsRequirement::class]
);

Html::footer();

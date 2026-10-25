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

Session::checkRight(PluginGrcmanagerIsmsRequirement::$rightname, READ);

Html::header(
    PluginGrcmanagerIsmsRequirement::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerIsmsRequirement::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('ismsrequirement');

// Issue #113 : en-tête explicatif + barre de complétude du SMSI (articles 4 à 10), au-dessus de la
// liste standard. Une seule barre empilée : conforme / en cours / non commencé.
$completion = PluginGrcmanagerIsmsRequirement::getCompletion();
$total      = max(1, $completion['total']);
$statusLabels = PluginGrcmanagerIsmsRequirement::getStatuses();
$bars = [];
$barColors = ['compliant' => 'bg-green', 'in_progress' => 'bg-blue', 'not_started' => 'bg-secondary'];
foreach ($barColors as $status => $color) {
    $bars[] = [
        'color' => $color,
        'width' => round(100 * $completion[$status] / $total, 1),
        'label' => $statusLabels[$status] ?? $status,
    ];
}
$statuses = [];
foreach (array_keys($statusLabels) as $status) {
    $statuses[] = [
        'badge_html' => PluginGrcmanagerIsmsRequirement::statusBadge($status),
        'count'      => $completion[$status],
    ];
}
global $CFG_GLPI;
TemplateRenderer::getInstance()->display('@grcmanager/isms_requirement_list_header.html.twig', [
    'report_url' => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/control.report.php',
    'completion' => $completion,
    'bars'       => $bars,
    'statuses'   => $statuses,
]);

$params = QueryBuilder::manageParams(PluginGrcmanagerIsmsRequirement::class, $_GET);

Search::showList(
    PluginGrcmanagerIsmsRequirement::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerIsmsRequirement::class]
);

Html::footer();

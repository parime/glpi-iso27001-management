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
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowMatrix;

// Issue #144, phase 3 : vue zone × zone (ou site × site) de la matrice des flux. Registre sensible.
Session::checkRight(\GlpiPlugin\Grcmanager\NetworkFlow::$rightname, READ);

$group = ($_GET['group'] ?? '') === NetworkFlowMatrix::GROUP_PREFIX
    ? NetworkFlowMatrix::GROUP_PREFIX
    : NetworkFlowMatrix::GROUP_ZONE;
$source = isset($_GET['src']) ? (string) $_GET['src'] : null;
$destination = isset($_GET['dst']) ? (string) $_GET['dst'] : null;
$data = \GlpiPlugin\Grcmanager\NetworkFlow::matrixData($group, $source, $destination);

$title = __('Zone matrix', 'grcmanager');
Html::header(
    $title,
    \GlpiPlugin\Grcmanager\NetworkFlow::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\NetworkFlow::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\NetworkFlow::class
);
TemplateRenderer::getInstance()->display('@grcmanager/network_flow_matrix.html.twig', $data + [
    'title'          => $title,
    'group'          => $group,
    'groups'         => [
        NetworkFlowMatrix::GROUP_ZONE   => __('Zone × zone', 'grcmanager'),
        NetworkFlowMatrix::GROUP_PREFIX => __('Site × site (zone name prefix)', 'grcmanager'),
    ],
    'max_zones'      => NetworkFlowMatrix::MAX_ZONES,
    'level_blocking' => NetworkFlowMatrix::LEVEL_BLOCKING,
    'level_warning'  => NetworkFlowMatrix::LEVEL_WARNING,
    'source'         => $source,
    'destination'    => $destination,
    'matrix_url'     => \GlpiPlugin\Grcmanager\NetworkFlow::getMatrixURL(),
    'list_url'       => \GlpiPlugin\Grcmanager\NetworkFlow::getSearchURL(),
]);
Html::footer();

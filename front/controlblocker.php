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
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlBlocker;
use GlpiPlugin\Grcmanager\Services\Control\ControlBlockerRules;

// Issue #867 : mesures bloquées de la SoA de l'entité active, triables. Lecture seule : chaque
// blocage renvoie à l'écran à corriger, rien n'est modifié.
Session::checkRight(ControlBlocker::RIGHTNAME, READ);
if (!ControlBlocker::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$sort = ControlBlockerRules::normalizeSort($_GET['sort'] ?? null);
$order = ControlBlockerRules::normalizeOrder($_GET['order'] ?? null);
$collected = ControlBlocker::collect();
$rows = ControlBlocker::rows($collected, $sort, $order);
$bySeverity = [];
foreach ($rows as $row) {
    foreach ($row['summary']['by_severity'] as $severity => $count) {
        $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + $count;
    }
}

Html::header(
    ControlBlocker::getTypeName(Session::getPluralNumber()),
    ControlBlocker::getSearchURL(),
    'grcmanager',
    Control::class,
    ControlBlocker::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, ControlBlocker::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('controlblocker');
TemplateRenderer::getInstance()->display('@grcmanager/control_blockers.html.twig', [
    'action'          => ControlBlocker::getSearchURL(),
    'rows'            => $rows,
    'blocked'         => count($rows),
    'total'           => count($collected),
    'by_severity'     => $bySeverity,
    'sort'            => $sort,
    'order'           => $order,
    'sort_labels'     => [
        'severity' => __('Severity', 'grcmanager'),
        'blockers' => __('Blockers', 'grcmanager'),
        'code'     => __('Control', 'grcmanager'),
    ],
    'categories'      => ControlBlocker::getCategories(),
    'severities'      => ControlBlocker::getSeverityStyles(),
    'consistency_url' => ConsistencyCheck::canView() ? ConsistencyCheck::getSearchURL() : '',
    'readiness_url'   => CertificationReadiness::canView() ? CertificationReadiness::getSearchURL() : '',
]);
Html::footer();

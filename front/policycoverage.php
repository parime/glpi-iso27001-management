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
use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyCoverageRules;

// Issue #879 : mesures applicables de la SoA de l'entité active non couvertes par un texte approuvé
// de la bibliothèque des politiques (clause 5.2, A.5.1). Lecture seule : chaque ligne renvoie à
// l'onglet « Textes de référence » de la mesure.
Session::checkRight(PolicyCoverage::RIGHTNAME, READ);
if (!PolicyCoverage::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$filter = PolicyCoverageRules::normalizeFilter($_GET['filter'] ?? null);
$report = PolicyCoverage::report($filter);

\GlpiPlugin\Grcmanager\Menu::header(
    PolicyCoverage::class,
    PolicyCoverage::getTypeName(Session::getPluralNumber()),
    PolicyCoverage::getSearchURL()
);
\GlpiPlugin\Grcmanager\Menu::showSection(PolicyCoverage::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('policycoverage');
TemplateRenderer::getInstance()->display('@grcmanager/policy_coverage.html.twig', $report + [
    'action'          => PolicyCoverage::getSearchURL(),
    'statuses'        => PolicyCoverage::getStatusStyles(),
    'filters'         => [
        PolicyCoverageRules::FILTER_GAPS => __('Gaps only', 'grcmanager'),
        PolicyCoverageRules::FILTER_ALL  => __('All applicable controls', 'grcmanager'),
    ],
    'csv_url'         => PolicyCoverage::getExportURL('csv', $filter),
    'pdf_url'         => PolicyCoverage::getExportURL('pdf', $filter),
    'policies_url'    => \GlpiPlugin\Grcmanager\Policy::getSearchURL(),
    'consistency_url' => ConsistencyCheck::canView() ? ConsistencyCheck::getSearchURL() : '',
]);
Html::footer();

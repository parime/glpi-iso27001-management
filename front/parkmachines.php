<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
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

include('../../../inc/includes.php');

// Issue #181 : machines les plus exposées (mêmes droits que les vulnérabilités du parc : droit du
// plugin et lecture native des logiciels). Lecture seule.
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);

Html::header(
    __('Most exposed machines', 'grcmanager'),
    \GlpiPlugin\Grcmanager\ParkVulnerability::getRankingURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\Menu::MACHINE_RANKING
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\Menu::MACHINE_RANKING
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('parkmachines');

$ranking = \GlpiPlugin\Grcmanager\ParkVulnerability::getMachineRanking();
TemplateRenderer::getInstance()->display('@grcmanager/park_machines.html.twig', [
    'rows'  => $ranking['rows'],
    'total' => $ranking['total'],
    'limit' => \GlpiPlugin\Grcmanager\ParkVulnerability::RANKING_LIMIT,
]);

Html::footer();

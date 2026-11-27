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
use GlpiPlugin\Grcmanager\Nis2Readiness;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

// Issue #1158 : parcours de mise en conformité NIS2. Lecture seule : l'état de chaque critère est
// calculé à l'ouverture, rien n'est enregistré. Sans le référentiel NIS2 activé, la page est
// refusée comme un droit manquant (elle n'apparaît alors nulle part dans le menu).
Session::checkRight(Nis2Readiness::RIGHTNAME, READ);
FrameworkConfig::checkEnabled(FrameworkSelection::NIS2);

$report = Nis2Readiness::report();

\GlpiPlugin\Grcmanager\Menu::header(Nis2Readiness::class, Nis2Readiness::getTypeName(), Nis2Readiness::getSearchURL());
\GlpiPlugin\Grcmanager\Menu::showSection(Nis2Readiness::class);
\GlpiPlugin\Grcmanager\PageHelp::show('nis2readiness');
TemplateRenderer::getInstance()->display('@grcmanager/nis2_readiness.html.twig', $report + [
    'styles'        => CertificationReadiness::getStatusStyles(),
    'iso_url'       => CertificationReadiness::canView() ? CertificationReadiness::getSearchURL() : '',
    'directive_url' => 'https://eur-lex.europa.eu/eli/dir/2022/2555/oj',
]);
Html::footer();

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
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

// Issue #379 : évaluation de conformité par référentiel (lecture seule).
global $CFG_GLPI;

Session::checkRight(FrameworkAssessment::RIGHTNAME, READ);

$today = date('Y-m-d');
$assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls($today), $today);
// Issue #789 : référentiel détaillé, retenu pour la session (clé inconnue ignorée).
$selected = FrameworkAssessment::selectedFramework($assessments, $_GET['framework'] ?? null);
$detail = FrameworkAssessment::detail($assessments, $selected);

Html::header(
    FrameworkAssessment::getTypeName(),
    FrameworkAssessment::getSearchURL(),
    'grcmanager',
    Dashboard::class,
    FrameworkAssessment::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Dashboard::class, FrameworkAssessment::class);
\GlpiPlugin\Grcmanager\PageHelp::show('frameworkassessment');
TemplateRenderer::getInstance()->display('@grcmanager/framework_assessment.html.twig', [
    'assessments'    => $assessments,
    'detail'         => $detail,
    'selected'       => $selected,
    'page_url'       => FrameworkAssessment::getSearchURL(),
    'config_url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php',
    'custom_url'     => CustomFramework::getSearchURL(),
    'bases'          => [
        'own'    => FrameworkAssessment::BASIS_OWN,
        'mapped' => FrameworkAssessment::BASIS_MAPPED,
        'none'   => FrameworkAssessment::BASIS_NONE,
    ],
    'nis2_key'       => FrameworkSelection::NIS2,
    // Issue #599 : NIS2 sans registre ReCyF, accès à la classification des entités.
    'recyf'          => [
        'config_url'    => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grcmanager-recyf',
        'register_url'  => \GlpiPlugin\Grcmanager\RecyfRequirement::getSearchURL(),
        'can_configure' => (bool) Session::haveRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE),
    ],
    'iso_key'        => FrameworkSelection::ISO27001,
]);
Html::footer();

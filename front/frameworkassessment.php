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
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\FrameworkAssessment;

// Issue #379 : évaluation de conformité par référentiel (lecture seule).
global $CFG_GLPI;

Session::checkRight(FrameworkAssessment::RIGHTNAME, READ);

$assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
$selected = (string) ($_GET['framework'] ?? '');
$detail = null;
foreach ($assessments as $label => $assessment) {
    if ($assessment['key'] === $selected) {
        $gaps = [];
        foreach ($assessment['gaps'] as $gap) {
            $gaps[] = $gap + ['title' => Control::getControlTitle($gap['code'])];
        }
        $detail = ['label' => $label, 'gaps' => $gaps] + $assessment;
    }
}

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
    'assessments' => $assessments,
    'detail'      => $detail,
    'page_url'    => FrameworkAssessment::getSearchURL(),
    'control_url' => Control::getFormURL(),
    'statuses'    => Control::getImplementationStatuses(),
    'config_url'  => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php',
]);
Html::footer();

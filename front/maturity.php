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

include('../../../inc/includes.php');

// Issue #166 : score de maturité cyber et détail du calcul.
Session::checkRight(\GlpiPlugin\Grcmanager\Maturity::RIGHTNAME, READ);

$current = \GlpiPlugin\Grcmanager\Maturity::current();
$themeLabels = \GlpiPlugin\Grcmanager\Control::getThemes();
$themes = [];
foreach ($current['themes'] as $theme => $value) {
    if ($value !== null) {
        $themes[] = ['label' => $themeLabels[$theme] ?? $theme, 'value' => $value];
    }
}

Html::header(
    \GlpiPlugin\Grcmanager\Maturity::getTypeName(),
    \GlpiPlugin\Grcmanager\Maturity::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerDashboard::class,
    \GlpiPlugin\Grcmanager\Maturity::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerDashboard::class, \GlpiPlugin\Grcmanager\Maturity::class);
PluginGrcmanagerPageHelp::show('maturity');
TemplateRenderer::getInstance()->display('@grcmanager/maturity.html.twig', [
    'score'            => $current['score'],
    'level'            => $current['level'],
    'base'             => $current['base'],
    'penalty'          => $current['penalty'],
    'penalties'        => $current['penalties'],
    'components'       => $current['components'],
    'component_labels' => \GlpiPlugin\Grcmanager\Maturity::getComponentLabels(),
    'level_labels'     => \GlpiPlugin\Grcmanager\Maturity::getLevelLabels(),
    'themes'           => $themes,
    'history'          => \GlpiPlugin\Grcmanager\Maturity::history(),
]);
Html::footer();

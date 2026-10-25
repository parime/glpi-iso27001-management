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
Session::checkRight(PluginGrcmanagerMaturity::RIGHTNAME, READ);

$current = PluginGrcmanagerMaturity::current();
$themeLabels = PluginGrcmanagerControl::getThemes();
$themes = [];
foreach ($current['themes'] as $theme => $value) {
    if ($value !== null) {
        $themes[] = ['label' => $themeLabels[$theme] ?? $theme, 'value' => $value];
    }
}

Html::header(
    PluginGrcmanagerMaturity::getTypeName(),
    PluginGrcmanagerMaturity::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerMaturity::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('maturity');
TemplateRenderer::getInstance()->display('@grcmanager/maturity.html.twig', [
    'score'            => $current['score'],
    'level'            => $current['level'],
    'base'             => $current['base'],
    'penalty'          => $current['penalty'],
    'penalties'        => $current['penalties'],
    'components'       => $current['components'],
    'component_labels' => PluginGrcmanagerMaturity::getComponentLabels(),
    'level_labels'     => PluginGrcmanagerMaturity::getLevelLabels(),
    'themes'           => $themes,
    'history'          => PluginGrcmanagerMaturity::history(),
]);
Html::footer();

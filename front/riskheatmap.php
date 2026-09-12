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

use GlpiPlugin\Grcmanager\Services\Risk\RiskHeatmapService;

include('../../../inc/includes.php');

Session::checkRight(PluginGrcmanagerRisk::$rightname, READ);

Html::header(
    __('Cartographie des risques', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerRisk::class
);

// Each cell links to the risk list, pre-filtered on that exact probability/impact combination
// (search option ids 3/4, see PluginGrcmanagerRisk::rawSearchOptions()) - the "interactive" half
// of ROADMAP.md "Version 2.2"'s heatmap, so a RSSI can go straight from "5 risks in this cell" to
// the actual list of which risks those are. Built here in plain PHP rather than in the template,
// same reasoning as front/risk.php's own $myRisksURL: this is what the search engine actually
// compares against for a dropdown-typed search option, not a Twig-side concern.
$heatmap = RiskHeatmapService::buildGrid();
foreach ($heatmap as $probability => &$impacts) {
    foreach ($impacts as $impact => &$cell) {
        $cell['url'] = PluginGrcmanagerRisk::getSearchURL() . '?' . http_build_query([
            'criteria' => [
                ['field' => 3, 'searchtype' => 'equals', 'value' => $probability],
                ['link' => 'AND', 'field' => 4, 'searchtype' => 'equals', 'value' => $impact],
            ],
        ]);
    }
}
unset($impacts, $cell);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/risk_heatmap.html.twig', [
    'heatmap'            => $heatmap,
    'risk_probabilities' => PluginGrcmanagerRisk::getProbabilities(),
    'risk_impacts'       => PluginGrcmanagerRisk::getImpacts(),
    'risk_list_url'      => PluginGrcmanagerRisk::getSearchURL(),
]);

Html::footer();

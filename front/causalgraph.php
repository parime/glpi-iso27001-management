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

// Issue #171 : effet domino de l'ensemble des risques et incidents reliés.
Session::checkRight(PluginGrcmanagerCausalLink::$rightname, READ);

$edges = PluginGrcmanagerCausalLink::allEdges();
$nodes = array_values(array_unique(array_merge(array_column($edges, 0), array_column($edges, 1))));

Html::header(
    PluginGrcmanagerCausalLink::getTypeName(2),
    PluginGrcmanagerCausalLink::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerRisk::class,
    PluginGrcmanagerCausalLink::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerRisk::class, PluginGrcmanagerCausalLink::class);
PluginGrcmanagerPageHelp::show('causalgraph');
TemplateRenderer::getInstance()->display('@grcmanager/causal_graph_page.html.twig', [
    'elements' => PluginGrcmanagerCausalLink::cytoscapeElements(['nodes' => $nodes, 'edges' => $edges]),
    'risk_url' => PluginGrcmanagerRisk::getSearchURL(),
]);
Html::footer();

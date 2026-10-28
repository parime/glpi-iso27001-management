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
Session::checkRight(\GlpiPlugin\Grcmanager\CausalLink::$rightname, READ);

$edges = \GlpiPlugin\Grcmanager\CausalLink::allEdges();
$nodes = array_values(array_unique(array_merge(array_column($edges, 0), array_column($edges, 1))));

Html::header(
    \GlpiPlugin\Grcmanager\CausalLink::getTypeName(2),
    \GlpiPlugin\Grcmanager\CausalLink::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\CausalLink::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Risk::class, \GlpiPlugin\Grcmanager\CausalLink::class);
PluginGrcmanagerPageHelp::show('causalgraph');
TemplateRenderer::getInstance()->display('@grcmanager/causal_graph_page.html.twig', [
    'elements' => \GlpiPlugin\Grcmanager\CausalLink::cytoscapeElements(['nodes' => $nodes, 'edges' => $edges]),
    'risk_url' => \GlpiPlugin\Grcmanager\Risk::getSearchURL(),
]);
Html::footer();

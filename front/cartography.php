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
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\Cartography;
use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;

// Issue #1082 : cartographie du SI par couches, du switch au processus métier.
Session::checkRight(Cartography::RIGHTNAME, READ);

$itemtype = (string) ($_GET['itemtype'] ?? '');
$itemsId = (int) ($_GET['items_id'] ?? 0);
$requested = (string) ($_GET['direction'] ?? '');
// Par défaut : l'impact vers le haut pour un équipement, les dépendances vers le bas pour un processus.
$direction = in_array($requested, [DependencyWalker::UP, DependencyWalker::DOWN], true)
    ? $requested
    : ($itemtype === BusinessProcess::class ? DependencyWalker::DOWN : DependencyWalker::UP);

$map = null;
$notFound = false;
if ($itemtype !== '' && $itemsId > 0) {
    // Le type est borné par la liste blanche des points de départ avant toute instanciation.
    $item = in_array($itemtype, Cartography::startItemtypes(), true) ? getItemForItemtype($itemtype) : false;
    if ($item instanceof CommonDBTM && $item->getFromDB($itemsId) && $item->canViewItem()) {
        $map = Cartography::map($item, $direction);
    } else {
        $notFound = true;
    }
}

\GlpiPlugin\Grcmanager\Menu::header(Cartography::class, Cartography::getTypeName(), Cartography::getSearchURL());
// Rubrique « Analyses de risques » : barre d'onglets commune, puis « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\Menu::showSection(Cartography::class);
\GlpiPlugin\Grcmanager\PageHelp::show('cartography');

TemplateRenderer::getInstance()->display('@grcmanager/cartography.html.twig', [
    'action'       => Cartography::getSearchURL(),
    'itemtypes'    => Cartography::startItemtypes(),
    'itemtype'     => $map !== null ? $itemtype : '',
    'items_id'     => $map !== null ? $itemsId : 0,
    'direction'    => $direction,
    'map'          => $map,
    'not_found'    => $notFound,
    'entry'        => $map === null && !$notFound ? Cartography::entryPoints() : null,
    'process_type' => BusinessProcess::class,
    'max_nodes'    => DependencyWalker::MAX_NODES,
]);

Html::footer();

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
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Cartography\DependencyWalker;
use GlpiPlugin\Grcmanager\Services\Cartography\Layers;

// Issue #1082 : cartographie du SI par couches, du switch au processus métier.
Session::checkRight(Cartography::RIGHTNAME, READ);

$itemtype = (string) ($_GET['itemtype'] ?? '');
$itemsId = (int) ($_GET['items_id'] ?? 0);
$requested = (string) ($_GET['direction'] ?? '');
// Par défaut : l'impact vers le haut pour un équipement, les dépendances vers le bas pour un processus.
$direction = in_array($requested, [DependencyWalker::UP, DependencyWalker::DOWN], true)
    ? $requested
    : ($itemtype === BusinessProcess::class ? DependencyWalker::DOWN : DependencyWalker::UP);

// Issue #1082, lot 2 : calque des flux (coché par défaut à la première ouverture) et couches affichées.
$flows = $itemtype === '' || isset($_GET['flows']);
$layers = array_values(array_intersect(
    Layers::ALL,
    array_map('intval', is_array($_GET['layers'] ?? null) ? $_GET['layers'] : [])
));
$layers = $layers === [] ? Layers::ALL : $layers;

$map = null;
$notFound = false;
if ($itemtype !== '' && $itemsId > 0) {
    // Le type est borné par la liste blanche des points de départ avant toute instanciation.
    $item = in_array($itemtype, Cartography::startItemtypes(), true) ? getItemForItemtype($itemtype) : false;
    if ($item instanceof CommonDBTM && $item->getFromDB($itemsId) && $item->canViewItem()) {
        $map = Cartography::map($item, $direction, ['flows' => $flows, 'layers' => $layers]);
    } else {
        $notFound = true;
    }
}

// Issue #1082, lot 2 : export de la carte pour le PCA/PRA et la documentation d'audit.
$export = (string) ($_GET['export'] ?? '');
if ($map !== null && $export === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(Cartography::pdfHtml($map));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cartographie-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if ($map !== null && $export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cartographie-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach (Cartography::exportRows($map) as $line) {
        // Une cellule commençant par = + - @ serait interprétée comme une formule par le tableur.
        fputcsv($out, array_map(
            static fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell,
            $line
        ), ';', '"', '');
    }
    fclose($out);
    exit;
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
    'flows'        => $flows,
    'layers'       => $layers,
    'layer_labels' => Layers::labels(),
]);

Html::footer();

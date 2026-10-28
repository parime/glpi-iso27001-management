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

// Issue #177 : points de défaillance uniques, et création du risque correspondant à la demande.
Session::checkRight(\GlpiPlugin\Grcmanager\ImpactAnalysis::RIGHTNAME, READ);

if (isset($_POST['create_risk'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Risk::$rightname, CREATE);
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    // $itemtype est borne par la liste blanche des actifs liables juste avant l'instanciation.
    // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
    $item = in_array($itemtype, \GlpiPlugin\Grcmanager\Risk::getLinkableItemtypes(), true) ? new $itemtype() : null;
    if ($item === null || !$item->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$item->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $analysis = \GlpiPlugin\Grcmanager\ImpactAnalysis::analyze($item);
    $riskId = \GlpiPlugin\Grcmanager\ImpactAnalysis::createSinglePointRisk($item, $analysis['spof_for']);
    if ($riskId > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Risk created in the register, to be assessed.',
            'grcmanager'
        )));
        Html::redirect(\GlpiPlugin\Grcmanager\Risk::getFormURLWithID($riskId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('This risk already exists for this item.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
}

// Issue #177, phase 2 : export pour le plan de continuité (CSV, séparateur « ; » pour les tableurs).
if (($_GET['export'] ?? '') === 'continuity') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plan-de-continuite-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach (\GlpiPlugin\Grcmanager\ImpactAnalysis::continuityRows() as $line) {
        // Une cellule commençant par = + - @ serait interprétée comme une formule par le tableur.
        fputcsv($out, array_map(
            static fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell,
            $line
        ), ';', '"', '');
    }
    fclose($out);
    exit;
}

Html::header(
    \GlpiPlugin\Grcmanager\ImpactAnalysis::getTypeName(),
    \GlpiPlugin\Grcmanager\ImpactAnalysis::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\ImpactAnalysis::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\ImpactAnalysis::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('impactanalysis');

TemplateRenderer::getInstance()->display('@grcmanager/impact_spof.html.twig', [
    'points'     => \GlpiPlugin\Grcmanager\ImpactAnalysis::allSinglePoints(),
    'action'     => \GlpiPlugin\Grcmanager\ImpactAnalysis::getSearchURL(),
    'can_create' => Session::haveRight(\GlpiPlugin\Grcmanager\Risk::$rightname, CREATE),
    'export_url' => \GlpiPlugin\Grcmanager\ImpactAnalysis::getSearchURL() . '?export=continuity',
]);

Html::footer();

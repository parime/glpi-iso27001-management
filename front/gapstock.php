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

use GlpiPlugin\Grcmanager\GapStockReport;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #870 : rapport du stock d'écarts (clauses 10.1 et 10.2). Droit du plugin, entités de la
// session ; lecture seule, sauf l'archivage du PDF comme document de preuve (droit de modifier).
Session::checkRight(GapStockReport::RIGHTNAME, READ);

// Archivage du PDF (jeton CSRF vérifié par GLPI).
if (isset($_POST['archive_gap_stock'])) {
    Session::checkRight(GapStockReport::RIGHTNAME, UPDATE);
    $filters = GapStockReport::filters($_POST);
    if (GapStockReport::archive($filters) > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Report archived as evidence of requirements 10.1 and 10.2 (Documents tab). The evidence pack includes it.',
            'grcmanager'
        )));
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(__('The report could not be archived.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::redirect(GapStockReport::getSearchURL() . '?' . http_build_query([
        'from' => $filters['from'],
        'to'   => $filters['to'],
    ]));
}

$filters = GapStockReport::filters($_GET);
$export = (string) ($_GET['export'] ?? '');
$filename = 'stock-ecarts-' . $filters['from'] . '-' . $filters['to'];

if ($export === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(GapStockReport::renderPdf($filters));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
    foreach (GapStockReport::csvRows($filters) as $line) {
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

Html::header(
    GapStockReport::getTypeName(),
    GapStockReport::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Audit::class,
    GapStockReport::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Audit::class, GapStockReport::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('gapstock');

GapStockReport::showPage($filters);

Html::footer();

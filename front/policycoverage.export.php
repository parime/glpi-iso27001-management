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

use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyCoverageRules;

// Issue #879 : rapport de couverture des mesures par les politiques, en CSV ou en PDF, calculé à la
// demande avec les droits et les entités de la personne, jamais conservé.
Session::checkRight(PolicyCoverage::RIGHTNAME, READ);
if (!PolicyCoverage::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$report = PolicyCoverage::report(PolicyCoverageRules::normalizeFilter($_GET['filter'] ?? null));
$filename = 'couverture-politiques-' . date('Y-m-d');

if (($_GET['format'] ?? 'csv') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(PolicyCoverage::renderPdf($report));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
foreach (PolicyCoverage::csvRows($report) as $line) {
    fputcsv($out, $line, ';', '"', '');
}
fclose($out);
exit;

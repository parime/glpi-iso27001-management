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

use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #608, lot 2 : registre du contexte de l'organisation pour l'auditeur (CSV ou PDF), à la
// demande, jamais enregistré. Droit général du plugin ; fiches des entités de la session seulement,
// éléments liés nommés seulement s'ils sont lisibles (risques : droit des registres sensibles).
Session::checkRight(ContextItem::$rightname, READ);
$filename = 'contexte-organisation-' . date('Y-m-d');

if (($_GET['format'] ?? '') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(ContextItem::reportHtml());
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
foreach (ContextItem::csvRows() as $line) {
    fputcsv($out, $line, ';');
}
fclose($out);
exit;

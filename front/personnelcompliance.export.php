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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\PersonnelCompliance;

// Issue #546 : export pour l'auditeur, en CSV ou en PDF, de la liste filtrée ou de la fiche d'une
// personne, calculé à la demande avec les droits et les entités de la personne qui l'exporte.
Session::checkLoginUser();

$usersId = (int) ($_GET['users_id'] ?? 0);
if ($usersId > 0) {
    if (!PersonnelCompliance::canViewFor($usersId)) {
        throw new AccessDeniedHttpException();
    }
} else {
    Session::checkRight(PersonnelCompliance::RIGHTNAME, READ);
    Session::checkRight(User::$rightname, READ);
}

$rows = PersonnelCompliance::exportRows(PersonnelCompliance::filters($_GET), $usersId);
$filename = 'conformite-personnel-' . date('Y-m-d');

if (($_GET['format'] ?? 'csv') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(PersonnelCompliance::renderPdf($rows));
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
foreach (PersonnelCompliance::csvLines($rows) as $line) {
    fputcsv($out, $line, ';', '"', '');
}
fclose($out);
exit;

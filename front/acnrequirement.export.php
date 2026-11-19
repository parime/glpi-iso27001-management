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
use GlpiPlugin\Grcmanager\AcnRequirement;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #703 : export de conformité du registre NIS2 Italia - ACN (CSV ou PDF), à la demande, jamais
// enregistré. Même entité que l'écran du registre (registres de la session seulement).
Session::checkRight(AcnRequirement::$rightname, READ);
if (!AcnRequirement::isFrameworkEnabled()) {
    throw new AccessDeniedHttpException();
}
$entityId = AcnRequirement::selectedRegister(AcnRequirement::availableRegisters(), $_GET['entities_id'] ?? null);
if ($entityId === null) {
    throw new AccessDeniedHttpException();
}
$filename = 'nis2-italia-acn-' . $entityId . '-' . date('Y-m-d');

if (($_GET['format'] ?? '') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(AcnRequirement::reportHtml($entityId));
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
foreach (AcnRequirement::csvRows($entityId) as $line) {
    fputcsv($out, $line, ';');
}
fclose($out);
exit;

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
use GlpiPlugin\Grcmanager\RecyfRequirement;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #599 : export de conformité du registre ReCyF (NIS2 France) (CSV ou PDF), à la demande, jamais
// enregistré. Même entité que l'écran du registre (registres de la session seulement).
Session::checkRight(RecyfRequirement::$rightname, READ);
if (!RecyfRequirement::isFrameworkEnabled()) {
    throw new AccessDeniedHttpException();
}
$entityId = RecyfRequirement::selectedRegister(RecyfRequirement::availableRegisters(), $_GET['entities_id'] ?? null);
if ($entityId === null) {
    throw new AccessDeniedHttpException();
}
$filename = 'nis2-recyf-' . $entityId . '-' . date('Y-m-d');

if (($_GET['format'] ?? '') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(RecyfRequirement::reportHtml($entityId));
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
foreach (RecyfRequirement::csvRows($entityId) as $line) {
    fputcsv($out, $line, ';');
}
fclose($out);
exit;

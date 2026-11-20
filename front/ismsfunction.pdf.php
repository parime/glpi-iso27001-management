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
use GlpiPlugin\Grcmanager\CompetenceMatrix;
use GlpiPlugin\Grcmanager\IsmsFunction;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #774 : fiche de poste « sécurité » d'une fonction (droit du plugin et entité de la fonction),
// ou dossier complet des fonctions et des écarts (droit du plugin et lecture des utilisateurs),
// calculés à la demande avec les droits et les entités de la personne, sans rien conserver.
Session::checkRight(IsmsFunction::$rightname, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $function = new IsmsFunction();
    $function->check($id, READ);
    $html = CompetenceMatrix::renderPdf([$function], false);
    $filename = 'fiche-fonction-' . $id . '-' . date('Y-m-d') . '.pdf';
} else {
    if (!CompetenceMatrix::canView()) {
        throw new AccessDeniedHttpException();
    }
    $html = CompetenceMatrix::renderPdf(CompetenceMatrix::visibleFunctionItems(), true);
    $filename = 'competences-' . date('Y-m-d') . '.pdf';
}

$pdf = PdfRenderer::renderHtmlToPdf($html);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

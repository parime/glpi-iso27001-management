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
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\CertificationResponse;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #772, lot 3 : plan d'actions correctives d'un audit de certification, à envoyer à
// l'organisme. Lecture seule : droit du plugin, lecture de l'audit (droit et entité), audit de
// certification uniquement.
Session::checkRight(CertificationResponse::RIGHTNAME, READ);
if (!CertificationResponse::canView()) {
    throw new AccessDeniedHttpException();
}

$auditId = max(0, (int) ($_GET['audit'] ?? 0));
$audit = new Audit();
$audit->check($auditId, READ);
$report = CertificationResponse::report($auditId);
if ($report === null) {
    // Audit interne ou annulé : aucun plan d'actions pour l'organisme.
    throw new NotFoundHttpException();
}

$pdf = PdfRenderer::renderHtmlToPdf(CertificationResponse::renderPdf($report));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="plan-actions-organisme-' . $auditId . '-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

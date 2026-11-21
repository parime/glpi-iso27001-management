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
use GlpiPlugin\Grcmanager\PhysicalSecurityAssessment;

// Issue #778, lot 2 : rapport PDF de l'évaluation de la sécurité physique d'un site, pour l'auditeur.
// Droit du plugin et entité de l'évaluation (check() du cœur) ; calculé à la demande, rien n'est conservé.
Session::checkRight(PhysicalSecurityAssessment::RIGHTNAME, READ);

$assessment = new PhysicalSecurityAssessment();
$assessment->check((int) ($_GET['id'] ?? 0), READ);

$pdf = PdfRenderer::renderHtmlToPdf(PhysicalSecurityAssessment::renderPdf($assessment));
$filename = 'securite-physique-' . $assessment->getID() . '-' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

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
use GlpiPlugin\Grcmanager\ResponsibilityActivity;

// Issue #612 : matrice des responsabilités en PDF, calculée à la demande avec les droits et les
// entités de la personne, sans rien conserver (l'archivage passe par la page de la matrice).
Session::checkRight(ResponsibilityActivity::$rightname, READ);

$pdf = PdfRenderer::renderHtmlToPdf(ResponsibilityActivity::renderPdf());
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="matrice-responsabilites-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

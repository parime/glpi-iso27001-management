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

use GlpiPlugin\Grcmanager\MaturityCampaign;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #603 : rapport PDF d'une campagne de maturité par mesure, pour la revue de direction. Droit
// du plugin et entité de la campagne (check() du cœur) ; calculé à la demande, rien n'est conservé.
Session::checkRight(MaturityCampaign::RIGHTNAME, READ);

$campaign = new MaturityCampaign();
$campaign->check((int) ($_GET['id'] ?? 0), READ);

$pdf = PdfRenderer::renderHtmlToPdf(MaturityCampaign::renderPdf($campaign));
$filename = 'maturite-par-mesure-' . $campaign->getID() . '-' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

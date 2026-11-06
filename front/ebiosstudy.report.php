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

use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;

// Issue #376 : rapport PDF d'une étude EBIOS RM, complet ou limité à un atelier, à la demande.
Session::checkRight(EbiosStudy::RIGHTNAME, READ);

$study = new EbiosStudy();
if (!$study->getFromDB((int) ($_GET['id'] ?? 0)) || !$study->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
// Atelier inconnu ou absent : le rapport complet.
$workshop = (int) ($_GET['workshop'] ?? 0);
if (!array_key_exists($workshop, EbiosStudy::getWorkshopLabels())) {
    $workshop = 0;
}

// Registre sensible : chaque export est tracé (Administration > Journaux).
SensitiveNotification::logExport(EbiosStudy::class, EbiosStudy::getTypeName(1) . ' (PDF)');

$pdf = PdfRenderer::renderHtmlToPdf($study->renderReport($workshop));
$suffix = $workshop > 0 ? '-atelier-' . $workshop : '';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="etude-ebios-' . $study->getID() . $suffix . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

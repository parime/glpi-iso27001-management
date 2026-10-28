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

include('../../../inc/includes.php');

// Issue #139, phase 2 : rapport PDF d'une session (émargement, notes, taux de réussite),
// généré à la demande pour l'auditeur.
Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, READ);

$training = new \GlpiPlugin\Grcmanager\Training();
if (!$training->getFromDB((int) ($_GET['id'] ?? 0)) || !$training->canViewItem()) {
    Html::displayNotFoundError();
}

$pdf = PdfRenderer::renderHtmlToPdf(\GlpiPlugin\Grcmanager\Training::renderSessionReport($training));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="session-formation-' . $training->getID() . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

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

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

/**
 * Issue #607 : fiche PDF d'un test d'efficacité pour l'auditeur (résultat, relecture, échantillon
 * et résultat de chaque élément tiré), à la demande et jamais enregistrée. Droit de lire la mesure.
 */
Session::checkRight(ControlTest::RIGHTNAME, READ);

$testsId = (int) ($_GET['id'] ?? 0);
if ($testsId <= 0) {
    throw new BadRequestHttpException();
}
$test = new ControlTest();
$test->check($testsId, READ);

$pdf = PdfRenderer::renderHtmlToPdf(ControlTest::renderRecord($test));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="test-efficacite-' . $testsId . '-' . date('Ymd') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

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

use GlpiPlugin\Grcmanager\OperatingProcedure;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Policy;

// Issue #775 : registre des procédures d'exploitation en PDF, à la demande, jamais enregistré.
// Droits de la bibliothèque des politiques.
Session::checkRight(Policy::$rightname, READ);

$pdf = PdfRenderer::renderHtmlToPdf(OperatingProcedure::reportHtml());
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="procedures-exploitation-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

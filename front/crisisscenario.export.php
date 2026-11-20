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

use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #776 : scénarios de crise sécurité de l'information en PDF, à la demande, jamais enregistré.
// Droit de la continuité ; seuls les scénarios et processus des entités de la session y figurent.
Session::checkRight(CrisisScenario::$rightname, READ);

$pdf = PdfRenderer::renderHtmlToPdf(CrisisScenario::reportHtml());
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="scenarios-crise-ssi-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

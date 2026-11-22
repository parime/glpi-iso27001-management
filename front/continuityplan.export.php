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

use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #1083 : plan de continuité ou de reprise en PDF, à la demande, jamais enregistré, pour le
// consulter quand GLPI est indisponible. Version actuelle, ou version figée soumise à l'approbation
// (?version=). Droit de la continuité et entité du plan.
Session::checkRight(ContinuityPlan::$rightname, READ);
$item = new ContinuityPlan();
$item->check((int) ($_GET['id'] ?? 0), READ);

$pdf = PdfRenderer::renderHtmlToPdf($item->pdfHtml((int) ($_GET['version'] ?? 0)));
$name = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '-',
    (string) $item->fields['plan_type'] . '-' . (string) $item->fields['version']
);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="plan-' . trim((string) $name, '-') . '-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

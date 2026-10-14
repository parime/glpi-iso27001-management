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
use GlpiPlugin\Grcmanager\Services\Control\SoaReportBuilder;

include('../../../inc/includes.php');

// Export à la demande, jamais persisté (pas de Document GLPI créé) : ce PDF n'est qu'une mise en
// forme des données déjà réelles de la Déclaration d'Applicabilité (ROADMAP.md "Version 1.5").
Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

// Issue #112 : même HTML que les versions figées soumises à approbation, voir SoaReportBuilder.
$html = SoaReportBuilder::renderHtml();

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="soa-annexe-a-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

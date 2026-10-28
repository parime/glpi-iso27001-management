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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

include('../../../inc/includes.php');

// Issue #144, phase 2 : rapport PDF de la matrice des flux, généré à la demande (jamais persisté).
Session::checkRight(\GlpiPlugin\Grcmanager\NetworkFlow::$rightname, READ);
// Registre sensible : chaque export est tracé (Administration > Journaux).
\GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::logExport(
    'GlpiPlugin\\Grcmanager\\NetworkFlow',
    __('Network flow matrix', 'grcmanager') . ' (PDF)'
);

$html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/network_flow_report.html.twig', [
    'report_title'       => __('Network flow matrix', 'grcmanager'),
    'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
] + \GlpiPlugin\Grcmanager\NetworkFlow::reportData());

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="matrice-des-flux-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

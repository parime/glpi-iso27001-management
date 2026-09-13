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
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;

include('../../../inc/includes.php');

// Export à la demande, jamais persisté (pas de Document GLPI créé) : ce PDF n'est qu'une mise en
// forme des données déjà réelles de la Déclaration d'Applicabilité (ROADMAP.md "Version 1.5").
Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

global $DB;

$titles = PluginGrcmanagerControl::getControlTitles();
$themes = PluginGrcmanagerControl::getThemes();
$applicabilities = PluginGrcmanagerControl::getApplicabilities();
$implementationStatuses = PluginGrcmanagerControl::getImplementationStatuses();

$controls = [];
foreach (
    $DB->request([
        'FROM'  => PluginGrcmanagerControl::getTable(),
        'ORDER' => 'code ASC',
    ]) as $row
) {
    $controls[] = [
        'code'                  => $row['code'],
        'theme'                 => $themes[$row['theme']] ?? $row['theme'],
        'title'                 => $titles[$row['code']] ?? $row['code'],
        'applicability'         => $applicabilities[$row['applicability']] ?? $row['applicability'],
        'implementation_status' => $implementationStatuses[$row['implementation_status']]
            ?? $row['implementation_status'],
        'justification'         => $row['justification'],
    ];
}

$html = \Glpi\Application\View\TemplateRenderer::getInstance()->render('@grcmanager/pdf/soa_report.html.twig', [
    'report_title'         => __('Déclaration d\'Applicabilité — Annexe A ISO/IEC 27001:2022', 'grcmanager'),
    'generated_at_label'   => sprintf(__('Généré le %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
    'controls'             => $controls,
    'by_applicability'     => DashboardCardService::soaByApplicability(),
    'by_status'            => DashboardCardService::soaByImplementationStatus(),
    'reviewed_count'       => DashboardCardService::soaReviewedCount(),
    'total_count'          => count($controls),
]);

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="soa-annexe-a-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

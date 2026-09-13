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

// Export à la demande, jamais persisté (pas de Document GLPI créé) — ROADMAP.md "Version 1.5".
Session::checkRight(PluginGrcmanagerRisk::$rightname, READ);

global $DB;

$categories = PluginGrcmanagerRisk::getCategories();
$treatments = PluginGrcmanagerRisk::getTreatments();
$statuses   = PluginGrcmanagerRisk::getStatuses();
$levels     = PluginGrcmanagerRisk::getImpacts(); // shared low/medium/high/critical scale

$risks = [];
foreach (
    $DB->request([
        'FROM'  => PluginGrcmanagerRisk::getTable(),
        'ORDER' => 'title ASC',
    ]) as $row
) {
    $risks[] = [
        'title'       => $row['title'],
        'category'    => $categories[$row['category']] ?? $row['category'],
        'risk_level'  => $levels[$row['risk_level']] ?? $row['risk_level'],
        'treatment'   => $treatments[$row['treatment']] ?? $row['treatment'],
        'status'      => $statuses[$row['status']] ?? $row['status'],
        'owner'       => $row['users_id'] ? getUserName((int) $row['users_id']) : '',
        'review_date' => $row['review_date'] ? Html::convDate($row['review_date']) : '',
    ];
}

$byLevel = DashboardCardService::risksByLevel();
foreach ($byLevel['data'] as &$entry) {
    $entry['label'] = $levels[$entry['label']] ?? $entry['label'];
}
unset($entry);

$html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
    '@grcmanager/pdf/risk_register_report.html.twig',
    [
        'report_title'       => __('Registre des risques', 'grcmanager'),
        'generated_at_label' => sprintf(
            __('Généré le %s', 'grcmanager'),
            Html::convDateTime(date('Y-m-d H:i:s'))
        ),
        'risks'              => $risks,
        'by_level'           => $byLevel,
        'open_count'         => DashboardCardService::openRisksCount(),
        'total_count'        => count($risks),
    ]
);

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registre-risques-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

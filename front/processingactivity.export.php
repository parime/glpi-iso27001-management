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
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Privacy\ProcessingActivityRules;

// Issue #375 : export du registre des traitements (article 30), à la demande et jamais persisté.
// Seules les fiches des entités de la session ; les éléments liés que la personne ne peut pas lire
// ne sont pas nommés.
Session::checkRight(ProcessingActivity::$rightname, READ);

$format = ($_GET['format'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';
// Donnée sensible : chaque export est tracé (Administration > Journaux, et journal d'audit).
SensitiveNotification::logExport(
    ProcessingActivity::class,
    ProcessingActivity::getTypeName(2) . ' (' . strtoupper($format) . ')'
);
AuditLog::record('export', ProcessingActivity::class, 0, 0, 'success', ['format' => $format]);

$rows = ProcessingActivity::registerRows();
$columns = ProcessingActivity::registerColumns();
$filename = 'registre-traitements-' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
    fputcsv($out, array_values($columns), ';', '"', '');
    foreach ($rows as $row) {
        // Textes saisis librement : neutralisés contre l'injection de formules (= + - @).
        $cells = [];
        foreach (array_keys($columns) as $key) {
            $cells[] = ProcessingActivityRules::csvCell($row[$key] ?? '');
        }
        fputcsv($out, $cells, ';', '"', '');
    }
    fclose($out);
    exit;
}

$entity = new Entity();
$entity->getFromDB(Session::getActiveEntity());
$html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/processing_register_report.html.twig', [
    'report_title'       => __('Record of processing activities', 'grcmanager'),
    'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
    'organisation'       => [
        'name'    => (string) ($entity->fields['completename'] ?? ''),
        'address' => trim(implode(' ', array_filter([
            (string) ($entity->fields['address'] ?? ''),
            (string) ($entity->fields['postcode'] ?? ''),
            (string) ($entity->fields['town'] ?? ''),
            (string) ($entity->fields['country'] ?? ''),
        ]))),
        'email'   => (string) ($entity->fields['email'] ?? ''),
        'phone'   => (string) ($entity->fields['phonenumber'] ?? ''),
        'dpo'     => ProcessingActivity::dpoContact(),
    ],
    'columns'            => $columns,
    'rows'               => $rows,
]);

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

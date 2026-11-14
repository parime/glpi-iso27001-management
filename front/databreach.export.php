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
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Privacy\ProcessingActivityRules;

// Issue #620 : export du registre des violations (article 33.5) pour l'autorité de contrôle, à la demande
// et jamais persisté. Seules les fiches des entités de la session ; un incident ou un traitement que la
// personne ne peut pas lire n'est pas nommé.
Session::checkRight(DataBreach::$rightname, READ);

$format = ($_GET['format'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';
// Donnée sensible : chaque export est tracé (Administration > Journaux, et journal d'audit).
SensitiveNotification::logExport(
    DataBreach::class,
    __('Breach register', 'grcmanager') . ' (' . strtoupper($format) . ')'
);
AuditLog::record('export', DataBreach::class, 0, 0, 'success', ['format' => $format]);

$rows = DataBreach::registerRows();
$columns = DataBreach::registerColumns();
$filename = 'registre-violations-' . date('Y-m-d');

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
$html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/data_breach_register.html.twig', [
    'report_title'       => __('Register of personal data breaches', 'grcmanager'),
    'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
    'organisation'       => [
        'name' => (string) ($entity->fields['completename'] ?? ''),
        'dpo'  => ProcessingActivity::dpoContact(),
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

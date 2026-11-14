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

use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\Services\Policy\AcknowledgementRules;

// Issue #535 : liste des retardataires d'une campagne (CSV) ou rapport complet (PDF), à la demande,
// jamais persisté. Lignes des entités de la session seulement.
Session::checkRight(Policy::$rightname, READ);

$campaign = PolicyAcknowledgement::getCampaign((int) ($_GET['id'] ?? 0));
if ($campaign === null) {
    throw new NotFoundHttpException();
}
$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
$filename = sprintf(
    'retardataires-politique-%d-v%s-%s',
    (int) $campaign['plugin_grcmanager_policies_id'],
    preg_replace('/[^A-Za-z0-9.]/', '', (string) $campaign['version']),
    date('Y-m-d')
);

if ($format === 'csv') {
    $summary = PolicyAcknowledgement::summarize($campaign);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
    fputcsv($out, [
        __('Person', 'grcmanager'),
        __('Entity', 'grcmanager'),
        __('Version', 'grcmanager'),
        __('Deadline', 'grcmanager'),
        __('Last reminder', 'grcmanager'),
    ], ';', '"', '');
    foreach ($summary['latecomers'] as $line) {
        fputcsv($out, [
            AcknowledgementRules::csvCell($line['user_name']),
            AcknowledgementRules::csvCell($line['entity']),
            AcknowledgementRules::csvCell($campaign['version']),
            Html::convDate($campaign['due_date']),
            $line['date_reminder'] !== null ? Html::convDateTime($line['date_reminder']) : '',
        ], ';', '"', '');
    }
    fclose($out);
    exit;
}

$pdf = PdfRenderer::renderHtmlToPdf(PolicyAcknowledgement::renderReport($campaign));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

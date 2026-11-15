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

use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;

// Issue #614 : rapport de préparation à l'audit (liste des écarts de cohérence), en CSV ou en PDF,
// calculé à la demande avec les droits et les entités de la personne, jamais conservé.
global $CFG_GLPI;

Session::checkRight(ConsistencyCheck::RIGHTNAME, READ);

$filters = ConsistencyRules::filters($_GET);
$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
$report = ConsistencyCheck::report($filters);
$filename = 'coherence-smsi-' . date('Y-m-d');

if ($format === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(ConsistencyCheck::renderPdf($report));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
fputcsv($out, [
    __('Area', 'grcmanager'),
    __('Clause', 'grcmanager'),
    __('Rule', 'grcmanager'),
    __('Severity', 'grcmanager'),
    __('Type', 'grcmanager'),
    __('Item', 'grcmanager'),
    __('Detail', 'grcmanager'),
    __('How to fix', 'grcmanager'),
    __('Link', 'grcmanager'),
], ';', '"', '');
$base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
foreach ($report['areas'] as $area) {
    foreach ($area['rules'] as $rule) {
        foreach ($rule['gaps'] as $gap) {
            $url = (string) $gap['url'];
            if ($base !== '' && str_starts_with($url, (string) $CFG_GLPI['root_doc'] . '/')) {
                $url = $base . substr($url, strlen((string) $CFG_GLPI['root_doc']));
            }
            fputcsv($out, [
                ConsistencyRules::csvCell($area['label']),
                ConsistencyRules::csvCell($rule['clause']),
                ConsistencyRules::csvCell($rule['label']),
                ConsistencyRules::csvCell($rule['severity']['label']),
                ConsistencyRules::csvCell($gap['type_label']),
                ConsistencyRules::csvCell($gap['name']),
                ConsistencyRules::csvCell($gap['detail']),
                ConsistencyRules::csvCell($rule['fix']),
                $url,
            ], ';', '"', '');
        }
    }
}
fclose($out);
exit;

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
Session::checkRight(\GlpiPlugin\Grcmanager\Audit::$rightname, READ);

global $DB;

$auditStatuses = \GlpiPlugin\Grcmanager\Audit::getStatuses();

$audits = [];
foreach (
    $DB->request([
        'FROM'  => \GlpiPlugin\Grcmanager\Audit::getTable(),
        'ORDER' => 'planned_date ASC',
    ]) as $row
) {
    $audits[] = [
        'title'        => $row['title'],
        'auditor'      => $row['users_id'] ? getUserName((int) $row['users_id']) : '',
        'status'       => $auditStatuses[$row['status']] ?? $row['status'],
        'planned_date' => $row['planned_date'] ? Html::convDate($row['planned_date']) : '',
        'actual_date'  => $row['actual_date'] ? Html::convDate($row['actual_date']) : '',
    ];
}

$findingTypes = \GlpiPlugin\Grcmanager\Nonconformity::getFindingTypes();
$severities   = \GlpiPlugin\Grcmanager\Nonconformity::getSeverities();
$ncStatuses   = \GlpiPlugin\Grcmanager\Nonconformity::getStatuses();

$findings = [];
foreach (
    $DB->request([
        'FROM'  => \GlpiPlugin\Grcmanager\Nonconformity::getTable(),
        'ORDER' => 'due_date ASC',
    ]) as $row
) {
    $findings[] = [
        'title'        => $row['title'],
        'finding_type' => $findingTypes[$row['finding_type']] ?? $row['finding_type'],
        'severity'     => $severities[$row['severity']] ?? $row['severity'],
        'status'       => $ncStatuses[$row['status']] ?? $row['status'],
        'responsible'  => $row['users_id'] ? getUserName((int) $row['users_id']) : '',
        'due_date'     => $row['due_date'] ? Html::convDate($row['due_date']) : '',
    ];
}

$byStatus = DashboardCardService::auditsByStatus();
foreach ($byStatus['data'] as &$entry) {
    $entry['label'] = $auditStatuses[$entry['label']] ?? $entry['label'];
}
unset($entry);

$html = \Glpi\Application\View\TemplateRenderer::getInstance()->render('@grcmanager/pdf/audit_report.html.twig', [
    'report_title'          => __('Internal audit program and CAPA', 'grcmanager'),
    'generated_at_label'    => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
    'audits'                => $audits,
    'findings'              => $findings,
    'by_status'             => $byStatus,
    'open_nonconformities'  => DashboardCardService::openNonconformitiesCount(),
    'overdue_capa'          => DashboardCardService::overdueCapaCount(),
    'total_audits'          => count($audits),
]);

$pdf = PdfRenderer::renderHtmlToPdf($html);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="audit-capa-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

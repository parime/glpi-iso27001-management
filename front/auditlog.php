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

// Issue #371 : journal d'audit des incidents de sécurité (lecture, vérification, export CSV).
Session::checkRight(AuditLog::RIGHTNAME, READ);

$filters = AuditLog::filtersFrom($_GET);
$where = AuditLog::criteria($filters);
$action = AuditLog::getSearchURL();
global $DB;

if (isset($_GET['csv'])) {
    AuditLog::record('export', AuditLog::class, 0, 0, 'success', ['filters' => array_filter($filters)]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="journal-audit-incidents.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, AuditLog::csvHeaders(), ';', '"', '');
        foreach ($DB->request(['FROM' => AuditLog::getTable(), 'WHERE' => $where, 'ORDER' => 'id ASC']) as $row) {
            fputcsv($out, AuditLog::csvRow($row), ';', '"', '');
        }
        fclose($out);
    }

    return;
}

$verification = null;
if (isset($_POST['verify'])) {
    $verification = AuditLog::verifyAll();
}

Html::header(AuditLog::getTypeName(), $_SERVER['PHP_SELF'], 'helpdesk', PluginGrcmanagerSecurityIncident::class);
\GlpiPlugin\Grcmanager\PageHelp::show('auditlog');
TemplateRenderer::getInstance()->display('@grcmanager/audit_log.html.twig', [
    'action'       => $action,
    'csv_url'      => $action . '?' . http_build_query(array_filter($filters) + ['csv' => 1]),
    'filters'      => $filters,
    'verify_url'   => $action . (array_filter($filters) === [] ? '' : '?' . http_build_query(array_filter($filters))),
    'actions'      => AuditLog::getActions(),
    'verification' => $verification,
    'total'        => countElementsInTable(AuditLog::getTable(), $where),
    'entries'      => AuditLog::present(iterator_to_array($DB->request([
        'FROM'  => AuditLog::getTable(),
        'WHERE' => $where,
        'ORDER' => 'id DESC',
        'LIMIT' => 500,
    ]), false)),
]);
Html::footer();

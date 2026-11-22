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
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationSampling;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #772 : constats des audits de certification précédents et état de leur levée, avec un
// export PDF à remettre à l'auditeur à l'ouverture de l'audit suivant. Lecture seule ; audits et
// constats des entités de la session.
Session::checkRight(CertificationFinding::RIGHTNAME, READ);

$auditId = max(0, (int) ($_GET['audit'] ?? 0));
$openOnly = !empty($_GET['open']);
// Lot 2 : constats d'un processus du plan d'audit de l'organisme (processus des entités de la session).
$processId = max(0, (int) ($_GET['process'] ?? 0));
$report = CertificationFinding::report($auditId, $openOnly, $processId);

if (($_GET['format'] ?? '') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(CertificationFinding::renderPdf($report));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="constats-audits-certification-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

\GlpiPlugin\Grcmanager\Menu::header(
    CertificationFinding::class,
    CertificationFinding::getTypeName(),
    CertificationFinding::getSearchURL()
);
\GlpiPlugin\Grcmanager\Menu::showSection(CertificationFinding::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('certificationfinding');

$query = array_filter([
    'audit'   => $report['selected'],
    'open'    => $openOnly ? 1 : 0,
    'process' => $report['process'] !== null ? $report['process']['id'] : 0,
]);
TemplateRenderer::getInstance()->display('@grcmanager/certification_findings.html.twig', $report + [
    'page_url'    => CertificationFinding::getSearchURL(),
    'pdf_url'     => CertificationFinding::getSearchURL() . '?' . http_build_query($query + ['format' => 'pdf']),
    'lift_states' => CertificationFinding::getLiftStates(),
    'categories'  => CertificationFinding::getCategories(),
    'add_url'     => Audit::canCreate() ? Audit::getFormURL() : '',
    'plan_url'    => CertificationSampling::getSearchURL(),
]);
Html::footer();

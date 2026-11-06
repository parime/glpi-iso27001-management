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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;

// Issue #377 : plan de continuité (PCA / PRA) en PDF, à la demande et jamais persisté. Sans « id » :
// tous les processus lisibles (droit et entité) ; avec : ce seul processus, s'il est lisible.
Session::checkRight(BusinessProcess::$rightname, READ);

$process = null;
if (isset($_GET['id'])) {
    $process = new BusinessProcess();
    $process->check((int) $_GET['id'], READ);
}

// Carte des processus critiques : chaque export est tracé (Administration > Journaux, journal d'audit).
SensitiveNotification::logExport(BusinessProcess::class, BusinessProcess::getTypeName(2) . ' (PDF)');
AuditLog::record('export', BusinessProcess::class, $process !== null ? (int) $process->getID() : 0);

$pdf = PdfRenderer::renderHtmlToPdf(BusinessProcess::renderContinuityReport($process));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="pca-pra-'
    . ($process !== null ? 'processus-' . $process->getID() : date('Y-m-d')) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

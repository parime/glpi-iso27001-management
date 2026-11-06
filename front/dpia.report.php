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
use GlpiPlugin\Grcmanager\Dpia;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;

// Issue #375 : rapport PDF de l'AIPD d'une fiche, à la demande et jamais persisté.
Session::checkRight(Dpia::RIGHTNAME, READ);

$activity = new ProcessingActivity();
$activity->check((int) ($_GET['id'] ?? 0), READ);

// Donnée sensible : chaque export est tracé (Administration > Journaux, et journal d'audit).
SensitiveNotification::logExport(Dpia::class, Dpia::getTypeName(1) . ' (PDF)');
AuditLog::record('export', Dpia::class, (int) $activity->getID());

$pdf = PdfRenderer::renderHtmlToPdf(Dpia::renderReport($activity));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="aipd-' . $activity->getID() . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

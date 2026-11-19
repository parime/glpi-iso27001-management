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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditChecklist;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

/**
 * Issue #606 : rapport PDF d'un audit interne (synthèse, grille d'audit, demandes de preuves,
 * constats), à la demande et jamais enregistré ; le rapport archivé à la clôture de l'audit est le
 * même. Mêmes règles d'accès que les documents de l'audit.
 */
Session::checkLoginUser();

$audit = AuditChecklist::loadAudit((int) ($_GET['id'] ?? 0));
if ($audit === null) {
    throw new BadRequestHttpException();
}
$audit->check($audit->getID(), READ);
if (!AuditChecklist::canViewFor($audit)) {
    throw new AccessDeniedHttpException();
}

$pdf = PdfRenderer::renderHtmlToPdf(Audit::renderReport($audit));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="rapport-audit-' . $audit->getID() . '-' . date('Ymd') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;

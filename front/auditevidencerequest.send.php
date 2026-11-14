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
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\DocumentAccess;

/**
 * Issue #538 : pièce fournie pour une demande de preuves d'audit, même sans droit Documents ni
 * droit sur le plugin : la personne désignée, l'équipe de l'audit et le groupe RSSI, et l'auditeur
 * dont l'accès est en vigueur pour une pièce soumise (AuditEvidenceRequest::canDownloadPiece()).
 * Chaque téléchargement est historisé dans l'onglet « Accès aux documents » de l'audit.
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

$piece = AuditEvidenceRequest::getPieceRow((int) ($_GET['id'] ?? 0));
if ($piece === null || !AuditEvidenceRequest::canDownloadPiece($piece, $usersId)) {
    throw new AccessDeniedHttpException();
}
$request = AuditEvidenceRequest::getRow((int) $piece['plugin_grcmanager_auditevidencerequests_id']);
$document = new Document();
if (
    $request === null || !$document->getFromDB((int) $piece['documents_id'])
    || !is_file(GLPI_DOC_DIR . '/' . $document->fields['filepath'])
) {
    throw new NotFoundHttpException();
}

DocumentAccess::log($document->getID(), Audit::class, (int) $request['plugin_grcmanager_audits_id'], $usersId);

return $document->getAsResponse();

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
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;

/**
 * Issue #535 : document d'une politique, pour une personne de son public (même sans droit Documents
 * ni droit sur le plugin). Seuls les documents joints à cette politique ; chaque téléchargement est
 * historisé (\GlpiPlugin\Grcmanager\DocumentAccess).
 */

Session::checkLoginUser();

$policyId = (int) ($_GET['policies_id'] ?? 0);
$documentsId = (int) ($_GET['documents_id'] ?? 0);
$usersId = (int) Session::getLoginUserID();
if (!PolicyAcknowledgement::canDownload($policyId, $documentsId, $usersId)) {
    throw new AccessDeniedHttpException();
}
$document = new Document();
if (!$document->getFromDB($documentsId) || !is_file(GLPI_DOC_DIR . '/' . $document->fields['filepath'])) {
    throw new NotFoundHttpException();
}

\GlpiPlugin\Grcmanager\DocumentAccess::log($documentsId, \GlpiPlugin\Grcmanager\Policy::class, $policyId, $usersId);

return $document->getAsResponse();

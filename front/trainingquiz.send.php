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

include('../../../inc/includes.php');

/**
 * Issue #139 : téléchargement d'une copie de quiz, pour le participant, le formateur de la
 * session ou le groupe RSSI, même sans droit Documents ni droit sur le plugin. Chaque
 * téléchargement est historisé (PluginGrcmanagerDocumentAccess).
 */

Session::checkLoginUser();

$participationId = (int) ($_GET['id'] ?? 0);
if (!PluginGrcmanagerTrainingQuiz::canRead($participationId)) {
    throw new AccessDeniedHttpException();
}
$participation = PluginGrcmanagerTrainingQuiz::getParticipation($participationId);
$document = new Document();
if (
    $participation === null || $participation['documents_id'] <= 0
    || !$document->getFromDB($participation['documents_id'])
    || !is_file(GLPI_DOC_DIR . '/' . $document->fields['filepath'])
) {
    throw new NotFoundHttpException();
}

PluginGrcmanagerDocumentAccess::log(
    $document->getID(),
    PluginGrcmanagerTrainingQuiz::class,
    $participationId,
    (int) Session::getLoginUserID()
);

return $document->getAsResponse();

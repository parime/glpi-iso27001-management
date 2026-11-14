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

use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\AuditorAccess;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

/**
 * Issue #538 : « Mes demandes de preuves ». La personne désignée n'a souvent aucun droit sur le
 * plugin : la page n'exige qu'une session, ne montre que les demandes qui lui sont confiées, et
 * chaque action vérifie qu'elle en est bien la personne désignée (AuditEvidenceRequest).
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['requests_id'] ?? 0);
    $error = '';
    $success = '';
    if (isset($_POST['add_file'])) {
        global $CFG_GLPI;

        $file = $_FILES['evidence_file'] ?? null;
        $uploadError = is_array($file)
            ? EvidenceUploadRules::uploadError(
                is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
                is_int($file['size'] ?? null) ? $file['size'] : 0,
                (int) $CFG_GLPI['document_max_size'] * 1024 * 1024
            )
            : EvidenceUploadRules::ERROR_EMPTY;
        if ($uploadError !== null) {
            $error = ControlTest::uploadErrorMessage($uploadError);
        } elseif (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            $error = ControlTest::uploadErrorMessage(EvidenceUploadRules::ERROR_FAILED);
        } else {
            $error = AuditEvidenceRequest::addUploadedPiece(
                $requestId,
                $usersId,
                $file['tmp_name'],
                (string) ($file['name'] ?? '')
            );
            $success = __('Document added. Submit the request when everything is ready.', 'grcmanager');
        }
    } elseif (isset($_POST['add_existing'])) {
        $error = AuditEvidenceRequest::addExistingPiece($requestId, $usersId, (int) ($_POST['documents_id'] ?? 0));
        $success = __('Document added. Submit the request when everything is ready.', 'grcmanager');
    } elseif (isset($_POST['remove_piece'])) {
        $error = AuditEvidenceRequest::removePiece((int) ($_POST['pieces_id'] ?? 0), $usersId);
        $success = __('Document removed from the request.', 'grcmanager');
    } elseif (isset($_POST['submit_request'])) {
        $error = AuditEvidenceRequest::submit($requestId, $usersId, (string) ($_POST['submission_comment'] ?? ''));
        $success = __('Evidence submitted to the audit. Thank you.', 'grcmanager');
    }
    // « - » : le motif du refus est déjà en session (contrôle du fichier reçu).
    if ($error === '' && $success !== '') {
        Session::addMessageAfterRedirect($success);
    } elseif ($error !== '' && $error !== '-') {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::redirect(AuditEvidenceRequest::getMineURL() . ($requestId > 0 ? '#grc-evreq-' . $requestId : ''));
}

AuditorAccess::header(__('My evidence requests', 'grcmanager'), AuditEvidenceRequest::getMineURL());
\GlpiPlugin\Grcmanager\PageHelp::show('auditevidencerequest.mine');
AuditEvidenceRequest::showMine($usersId);
AuditorAccess::footer();

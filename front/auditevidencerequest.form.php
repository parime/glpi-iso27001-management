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

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\AuditorAccess;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

/**
 * Issue #538 : actions de l'onglet « Demandes de preuves » d'un audit. Toutes exigent le droit de
 * modifier l'audit (supprimer une demande : le droit de supprimer définitivement) : créer, importer,
 * modifier, revoir, relancer une demande ; donner ou révoquer l'accès d'un auditeur. Fournir les
 * pièces se fait depuis front/auditevidencerequest.mine.php, par la personne désignée.
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

$audit = new Audit();
$auditId = (int) ($_POST['plugin_grcmanager_audits_id'] ?? 0);
if (!$audit->getFromDB($auditId)) {
    throw new BadRequestHttpException();
}
$audit->check($auditId, UPDATE);
$back = AuditEvidenceRequest::getAuditTabURL($auditId);

/**
 * Demande de cet audit désignée par le formulaire, droit $right vérifié.
 */
$loadRequest = static function (int $right) use ($auditId): AuditEvidenceRequest {
    $request = new AuditEvidenceRequest();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$request->getFromDB($id) || (int) $request->fields['plugin_grcmanager_audits_id'] !== $auditId) {
        throw new BadRequestHttpException();
    }
    $request->check($id, $right);

    return $request;
};

if (isset($_POST['add'])) {
    $id = (new AuditEvidenceRequest())->add([
        'plugin_grcmanager_audits_id'   => $auditId,
        'plugin_grcmanager_controls_id' => (int) ($_POST['plugin_grcmanager_controls_id'] ?? 0),
        'reference'                     => (string) ($_POST['reference'] ?? ''),
        'description'                   => (string) ($_POST['description'] ?? ''),
        'users_id'                      => (int) ($_POST['users_id'] ?? 0),
        'due_date'                      => (string) ($_POST['due_date'] ?? ''),
    ]);
    if ($id) {
        Session::addMessageAfterRedirect(__('Evidence request added.', 'grcmanager'));
    }
} elseif (isset($_POST['update'])) {
    $request = $loadRequest(UPDATE);
    $request->update([
        'id'                            => $request->getID(),
        'plugin_grcmanager_controls_id' => (int) ($_POST['plugin_grcmanager_controls_id'] ?? 0),
        'reference'                     => (string) ($_POST['reference'] ?? ''),
        'description'                   => (string) ($_POST['description'] ?? ''),
        'users_id'                      => (int) ($_POST['users_id'] ?? 0),
        'due_date'                      => (string) ($_POST['due_date'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    $request = $loadRequest(PURGE);
    $request->delete(['id' => $request->getID()], true);
} elseif (isset($_POST['accept']) || isset($_POST['ask_completion'])) {
    $request = $loadRequest(UPDATE);
    $error = AuditEvidenceRequest::review(
        $request->getID(),
        $usersId,
        isset($_POST['accept']) ? 'accept' : 'to_complete',
        (string) ($_POST['review_comment'] ?? '')
    );
    if ($error === '') {
        Session::addMessageAfterRedirect(isset($_POST['accept'])
            ? __('Evidence accepted.', 'grcmanager')
            : __('The person has been asked to complete the evidence.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
} elseif (isset($_POST['remind'])) {
    $count = AuditEvidenceRequest::sendReminders(date('Y-m-d'), $auditId);
    Session::addMessageAfterRedirect(sprintf(__('%d reminder(s) sent.', 'grcmanager'), $count));
} elseif (isset($_POST['import_csv'])) {
    $file = $_FILES['csv_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            2 * 1024 * 1024
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = AuditEvidenceRequest::importCsv($audit, (string) file_get_contents($file['tmp_name']), [
            'users_id' => (int) ($_POST['users_id'] ?? 0),
            'due_date' => (string) ($_POST['due_date'] ?? ''),
        ]);
        Session::addMessageAfterRedirect(
            sprintf(__('%d evidence request(s) imported.', 'grcmanager'), $result['created']),
            false,
            $result['created'] > 0 ? INFO : WARNING
        );
        foreach (array_slice($result['errors'], 0, 20) as $message) {
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        }
    }
} elseif (isset($_POST['grant_access'])) {
    $error = AuditorAccess::grant($auditId, (int) ($_POST['users_id'] ?? 0), $_POST['date_end'] ?? '', $usersId);
    if ($error === '') {
        Session::addMessageAfterRedirect(__('Auditor access granted.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
} elseif (isset($_POST['revoke_access'])) {
    if (AuditorAccess::revoke($auditId, (int) ($_POST['accesses_id'] ?? 0), $usersId)) {
        Session::addMessageAfterRedirect(__('Auditor access revoked.', 'grcmanager'));
    }
} else {
    throw new BadRequestHttpException();
}

Html::redirect($back);

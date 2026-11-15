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

use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyVersion;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;

// Issue #611 : approbation des politiques de sécurité par la direction. Répondre : être approbateur
// désigné d'une version en attente (vérifié par PolicyVersion::answer()), sans droit sur le plugin.
// Demander, retirer, préparer une nouvelle version, désigner les approbateurs : droit de modifier la
// politique. Historique et PDF : lecture des politiques (ou approbateur désigné de la version).
$usersId   = (int) Session::getLoginUserID();
$canRead   = Session::haveRight(Policy::$rightname, READ);
$myPending = PolicyVersion::pendingForUser($usersId);
$selfUrl   = PolicyVersion::getPageURL();

/** Politique du formulaire, modifiable par l'utilisateur, sinon accès refusé. */
$editablePolicy = static function (): Policy {
    Session::checkRight(Policy::$rightname, UPDATE);
    $policy = new Policy();
    if (!$policy->can((int) ($_POST['policies_id'] ?? 0), UPDATE)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }

    return $policy;
};

// PDF figé servi par cette page (et non par front/document.send.php, qui exige des droits sur les
// Documents) : lecteurs des politiques, ou approbateur désigné de CETTE version.
if (isset($_GET['pdf'])) {
    $versionId = (int) $_GET['pdf'];
    $version   = new PolicyVersion();
    $document  = new Document();
    if (
        !PolicyVersion::canReadPdf($versionId, $usersId)
        || !$version->getFromDB($versionId)
        || !$document->getFromDB((int) $version->fields['documents_id'])
        || !is_file(GLPI_DOC_DIR . '/' . $document->fields['filepath'])
    ) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename((string) $document->fields['filename']) . '"');
    readfile(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
    exit;
}

// Historique des approbations d'une politique, en PDF (preuve pour l'audit, A.5.1).
if (isset($_GET['export'])) {
    Session::checkRight(Policy::$rightname, READ);
    $policy = new Policy();
    if (!$policy->can((int) $_GET['export'], READ)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $pdf = \GlpiPlugin\Grcmanager\Pdf\PdfRenderer::renderHtmlToPdf(
        PolicyVersion::renderRecordHtml($policy, PolicyVersion::getVersions($policy->getID()))
    );
    \GlpiPlugin\Grcmanager\AuditLog::record('export', Policy::class, $policy->getID(), 0, 'success', [
        'format' => 'pdf', 'report' => 'approval_history',
    ]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="approbations-politique-' . $policy->getID() . '.pdf"');
    echo $pdf;
    exit;
}

if (isset($_POST['answer'])) {
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (!$approve && $comment === '') {
        Session::addMessageAfterRedirect(htmlescape(__('Please give the reason for the rejection.', 'grcmanager')), false, ERROR);
    } elseif (PolicyVersion::answer((int) ($_POST['versions_id'] ?? 0), $usersId, $approve, $comment)) {
        Session::addMessageAfterRedirect(htmlescape($approve
            ? __('Approval recorded.', 'grcmanager')
            : __('Rejection recorded.', 'grcmanager')));
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('This version is not awaiting your answer.', 'grcmanager')), false, ERROR);
    }
    Html::redirect($selfUrl);
}

if (isset($_POST['save_approvers'])) {
    $policy = $editablePolicy();
    PolicyVersion::setDesignated(
        $policy,
        PolicyApprovalConfig::fromForm(
            (array) ($_POST['approver_users'] ?? []),
            (array) ($_POST['approver_groups'] ?? [])
        ),
        (int) ($_POST['approval_quorum'] ?? -1)
    );
    Session::addMessageAfterRedirect(htmlescape(__('Approvers saved.', 'grcmanager')));
    Html::back();
}

if (isset($_POST['submit_approval'])) {
    $policy = $editablePolicy();
    if (PolicyVersion::submit($policy, trim((string) ($_POST['comment'] ?? ''))) > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Version frozen and sent to the approvers.', 'grcmanager')));
    }
    Html::back();
}

if (isset($_POST['withdraw'])) {
    $policy = $editablePolicy();
    $versionId = (int) ($_POST['versions_id'] ?? 0);
    $version = new PolicyVersion();
    if (
        $version->getFromDB($versionId)
        && (int) $version->fields['plugin_grcmanager_policies_id'] === $policy->getID()
        && PolicyVersion::withdraw($versionId)
    ) {
        Session::addMessageAfterRedirect(htmlescape(__('Approval request withdrawn: the content can be modified again.', 'grcmanager')));
    }
    Html::back();
}

if (isset($_POST['new_version_submit'])) {
    $policy = $editablePolicy();
    if (PolicyVersion::prepareNewVersion($policy, (string) ($_POST['new_version'] ?? ''))) {
        Session::addMessageAfterRedirect(htmlescape(__('New version prepared: the policy is back to draft until it is approved.', 'grcmanager')));
    }
    Html::back();
}

// Page de l'approbateur : ses versions en attente. Sans droit de lecture des politiques, seuls les
// approbateurs qui ont une version en attente y accèdent.
if (!$canRead && $myPending === []) {
    Session::checkRight(Policy::$rightname, READ);
}

Html::header(
    __('Policy approval', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    Policy::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('policyapproval');

$rows = [];
foreach ($myPending as $row) {
    $rows[] = [
        'id'           => (int) $row['id'],
        'title'        => (string) $row['title'],
        'version'      => (string) $row['version'],
        'requested_by' => getUserName((int) $row['users_id']),
        'requested_at' => Html::convDateTime($row['date_creation']),
        'comment'      => (string) ($row['comment'] ?? ''),
        'rule'         => PolicyVersion::ruleLabel((int) $row['quorum']),
        'pdf_url'      => (int) $row['documents_id'] > 0 ? $selfUrl . '?pdf=' . (int) $row['id'] : null,
        'pdf_sha256'   => (string) $row['pdf_sha256'],
    ];
}

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/policy_approval.html.twig', [
    'my_pending'   => $rows,
    'action'       => $selfUrl,
    'csrf_token'   => Session::getNewCSRFToken(),
    'policies_url' => $canRead ? Policy::getSearchURL() : '',
]);

Html::footer();

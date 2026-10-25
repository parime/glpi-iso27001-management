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

use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;

include('../../../inc/includes.php');

global $CFG_GLPI;

// Issue #112 : approbation de la Déclaration d'Applicabilité par la direction. Lecture : même
// droit que la SoA ; demander une approbation : droit de modification ; répondre : être
// approbateur désigné de la version (vérifié par PluginGrcmanagerSoaVersion::answer()). Un
// membre de la direction n'a souvent aucun droit sur le plugin : un approbateur qui a une version
// en attente accède quand même à cette page, limitée à ses propres versions à approuver.
$canRead   = Session::haveRight(PluginGrcmanagerSoaVersion::$rightname, READ);
$myPending = PluginGrcmanagerSoaVersion::pendingForUser((int) Session::getLoginUserID());
if (!$canRead && $myPending === []) {
    Session::checkRight(PluginGrcmanagerSoaVersion::$rightname, READ);
}

// PDF figé servi par cette page (et non par front/document.send.php, qui exige des droits sur
// les Documents) : lecteurs de la SoA, ou approbateur désigné de CETTE version en attente.
if (isset($_GET['pdf'])) {
    $versionId = (int) $_GET['pdf'];
    $allowed   = $canRead || in_array($versionId, array_map('intval', array_column($myPending, 'id')), true);
    $version   = new PluginGrcmanagerSoaVersion();
    $document  = new Document();
    if (
        !$allowed
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

if (isset($_POST['request_approval'])) {
    Session::checkRight(PluginGrcmanagerSoaVersion::$rightname, UPDATE);
    $id = PluginGrcmanagerSoaVersion::requestApproval(
        array_map('intval', (array) ($_POST['approvers'] ?? [])),
        trim((string) ($_POST['comment'] ?? ''))
    );
    if ($id > 0) {
        Session::addMessageAfterRedirect(__('Version figée et envoyée aux approbateurs.', 'grcmanager'));
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php');
}

if (isset($_POST['answer'])) {
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (!$approve && $comment === '') {
        Session::addMessageAfterRedirect(__('Indiquez la raison du refus.', 'grcmanager'), false, ERROR);
    } elseif (
        PluginGrcmanagerSoaVersion::answer(
            (int) ($_POST['versions_id'] ?? 0),
            (int) Session::getLoginUserID(),
            $approve,
            $comment
        )
    ) {
        Session::addMessageAfterRedirect($approve
            ? __('Approbation enregistrée.', 'grcmanager')
            : __('Refus enregistré.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(__('Cette version n\'attend pas votre réponse.', 'grcmanager'), false, ERROR);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php');
}

Html::header(
    __('Approbation de la SoA', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('soaapproval');

global $DB, $CFG_GLPI;

$lastApproved = PluginGrcmanagerSoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
$pending      = PluginGrcmanagerSoaVersion::latest(SoaApprovalLogic::VERSION_PENDING);
$state        = SoaApprovalLogic::currentState(
    $lastApproved !== null ? (string) $lastApproved['fingerprint'] : null,
    PluginGrcmanagerSoaVersion::currentFingerprint()
);

$statuses = PluginGrcmanagerSoaVersion::getStatuses();
$answers  = PluginGrcmanagerSoaVersion::getAnswerLabels();
$selfUrl  = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php';
$history  = [];
// Historique et état de la SoA : réservés aux lecteurs de la SoA (un approbateur sans droit ne
// voit que ses propres versions à approuver).
$historyRows = $canRead
    ? $DB->request(['FROM' => PluginGrcmanagerSoaVersion::getTable(), 'ORDER' => 'version DESC'])
    : [];
foreach ($historyRows as $row) {
    $approvers = PluginGrcmanagerSoaVersion::getApprovers((int) $row['id']);
    foreach ($approvers as &$approver) {
        $approver['status_label']  = $answers[$approver['status']] ?? $approver['status'];
        $approver['date_answered'] = Html::convDateTime($approver['date_answered']);
    }
    unset($approver);
    $history[] = [
        'id'           => (int) $row['id'],
        'version'      => (int) $row['version'],
        'status'       => (string) $row['status'],
        'status_label' => $statuses[$row['status']] ?? $row['status'],
        'requested_at' => Html::convDateTime($row['date_creation']),
        'requested_by' => getUserName((int) $row['users_id']),
        'answered_at'  => Html::convDateTime($row['date_answered']),
        'comment'      => (string) ($row['comment'] ?? ''),
        'pdf_sha256'   => (string) ($row['pdf_sha256'] ?? ''),
        'pdf_url'      => (int) $row['documents_id'] > 0 ? $selfUrl . '?pdf=' . (int) $row['id'] : null,
        'approvers'    => $approvers,
    ];
}

$canRequest = Session::haveRight(PluginGrcmanagerSoaVersion::$rightname, UPDATE);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/soa_approval.html.twig', [
    'state'          => $state,
    'last_approved'  => $lastApproved === null ? null : [
        'version'     => (int) $lastApproved['version'],
        'answered_at' => Html::convDateTime($lastApproved['date_answered']),
    ],
    'pending'        => $pending === null ? null : ['version' => (int) $pending['version']],
    'my_pending'     => array_map(static fn (array $row): array => [
        'id'           => (int) $row['id'],
        'version'      => (int) $row['version'],
        'requested_by' => getUserName((int) $row['users_id']),
        'comment'      => (string) ($row['comment'] ?? ''),
        'pdf_url'      => (int) $row['documents_id'] > 0 ? $selfUrl . '?pdf=' . (int) $row['id'] : null,
    ], $myPending),
    'can_read'       => $canRead,
    'history'        => $history,
    'can_request'    => $canRequest,
    'approver_dropdown' => $canRequest ? User::dropdown([
        'name'     => 'approvers',
        'value'    => [],
        'multiple' => true,
        'width'    => '100%',
        'right'    => 'all',
        'display'  => false,
    ]) : '',
    'csrf_token'     => Session::getNewCSRFToken(),
    'soa_url'        => PluginGrcmanagerControl::getSearchURL(),
]);

Html::footer();

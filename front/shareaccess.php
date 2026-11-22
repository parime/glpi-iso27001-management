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

use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\ShareAccess;
use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

// Issue #846 : droits d'accès aux partages de fichiers Windows (A.5.15, A.5.18, A.8.3). Droit du
// plugin et droit des registres sensibles ; partages des entités de la session seulement. Le jeton
// CSRF est vérifié par GLPI sur tout POST.
Session::checkRight(ShareAccess::RIGHTNAME, READ);
Session::checkRight(Installer::SENSITIVE_RIGHT, READ);

// Fichier d'exemple fictif et script PowerShell de collecte (lecture seule), à télécharger.
// Lot 2 : exemple fictif de l'export CSV des droits SharePoint, OneDrive et Teams.
if (isset($_GET['example']) || isset($_GET['script'])) {
    $script = isset($_GET['script']);
    $file = $script ? 'Export-GrcShareAccess.ps1'
        : (($_GET['example'] ?? '') === 'm365' ? 'm365-share-access-example.csv' : 'share-access-example.csv');
    header($script ? 'Content-Type: text/plain; charset=UTF-8' : 'Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff');
    readfile(dirname(__DIR__) . '/resources/' . $file);

    return;
}

$shareId = (int) ($_POST['share'] ?? ($_GET['share'] ?? 0));
$back = $shareId > 0 ? ShareAccess::getShareURL($shareId) : ShareAccess::getSearchURL();
$manage = static function (): void {
    Session::checkRight(ShareAccess::RIGHTNAME, UPDATE);
    Session::checkRight(Installer::SENSITIVE_RIGHT, UPDATE);
};

$importMessages = static function (array $result): void {
    foreach ($result['errors'] as $code) {
        Session::addMessageAfterRedirect(htmlescape(ShareAccess::importErrorMessage($code)), false, ERROR);
    }
    if ($result['errors'] !== []) {
        return;
    }
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __(
            '%1$d share(s) and %2$d right(s) imported: %3$d new right(s), %4$d removed since the '
                . 'previous import.',
            'grcmanager'
        ),
        $result['shares'],
        $result['aces'],
        $result['added'],
        $result['removed']
    )), false, $result['shares'] > 0 ? INFO : WARNING);
    if ($result['truncated']) {
        Session::addMessageAfterRedirect(
            htmlescape(__('The file is too long: only its first lines were imported.', 'grcmanager')),
            false,
            WARNING
        );
    }
};

// Lot 2 : lecture de SharePoint, OneDrive ou Teams par Microsoft Graph, sur clic, en lecture seule.
if (isset($_POST['fetch_m365_graph'])) {
    $manage();
    $result = ShareAccess::importFromGraph((string) ($_POST['source'] ?? ''));
    if ($result['graph_error'] !== '') {
        Session::addMessageAfterRedirect(
            htmlescape(ShareAccess::graphErrorMessage($result['graph_error'])),
            false,
            ERROR
        );
    } else {
        $importMessages($result);
    }
    Html::redirect(ShareAccess::getSearchURL() . '#grc-share-m365');
}

if (isset($_POST['import_shares']) || isset($_POST['import_m365_shares'])) {
    $manage();
    $m365 = isset($_POST['import_m365_shares']);
    $file = $_FILES['share_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            ShareAccessRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $raw = (string) file_get_contents($file['tmp_name']);
        $importMessages($m365 ? ShareAccess::importM365Csv($raw) : ShareAccess::import($raw));
    }
    Html::redirect(ShareAccess::getSearchURL() . ($m365 ? '#grc-share-m365' : '#grc-share-import'));
}

if (isset($_POST['clear_shares'])) {
    $manage();
    ShareAccess::clear((string) ($_POST['server'] ?? ''), (string) ($_POST['source'] ?? ShareAccess::SOURCE_SMB));
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(ShareAccess::getSearchURL() . '#grc-share-import');
}

if (isset($_POST['save_share'])) {
    $manage();
    if (!ShareAccess::updateShare($shareId, $_POST)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Share saved.', 'grcmanager')));
    Html::redirect($back);
}

$reviewActions = [
    'start_review' => static fn (): string => ShareAccess::startReview($shareId),
    'decide'       => static fn (): string => ShareAccess::decide(
        (int) ($_POST['ace'] ?? 0),
        (string) ($_POST['decision'] ?? ''),
        (string) ($_POST['decision_comment'] ?? '')
    ),
    'close_review' => static fn (): string => ShareAccess::closeReview($shareId),
];
foreach ($reviewActions as $name => $action) {
    if (!isset($_POST[$name])) {
        continue;
    }
    if ($name === 'start_review') {
        $manage();
    }
    $code = $action();
    if ($code === 'rights') {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if ($code !== '') {
        Session::addMessageAfterRedirect(htmlescape(ShareAccess::reviewErrorMessage($code)), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(match ($name) {
            'start_review' => __('Review started: the owner can now decide for each right.', 'grcmanager'),
            'decide'       => __('Decision recorded.', 'grcmanager'),
            default        => __('Review closed.', 'grcmanager'),
        }));
    }
    Html::redirect($back . ($name === 'decide' ? '#grc-share-ace-' . (int) ($_POST['ace'] ?? 0) : ''));
}

if ($shareId > 0 && ShareAccess::getShare($shareId) === null) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

\GlpiPlugin\Grcmanager\Menu::header(
    ShareAccess::class,
    ShareAccess::getTypeName(),
    ShareAccess::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(ShareAccess::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('shareaccess');

ShareAccess::showPage($shareId);

Html::footer();

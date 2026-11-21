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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierAnswerFile;
use GlpiPlugin\Grcmanager\SupplierEvaluation;
use GlpiPlugin\Grcmanager\SupplierEvaluationFile;
use GlpiPlugin\Grcmanager\SupplierRisk;

// Issue #865 : réponse du fournisseur hors ligne. Export du questionnaire à remplir (lecture de
// l'évaluation), import du fichier retourné en deux temps : aperçu, puis enregistrement sur
// confirmation (modification de l'évaluation). Le jeton CSRF des formulaires est contrôlé par GLPI.
Session::checkRight(SupplierEvaluation::$rightname, READ);

$key = SupplierEvaluationFile::SESSION_KEY;
$evaluation = new SupplierEvaluation();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0 || !$evaluation->getFromDB($id)) {
    Html::redirect(SupplierRisk::getSearchURL());
}

// Fichier en attente : seul un jeton aléatoire créé ici est gardé en session (clé à part), le chemin
// est reconstruit sous GLPI_TMP_DIR ; rien du chemin ne vient de la requête.
$pendingFile = static function () use ($key): string {
    $token = $_SESSION[$key . '_file'] ?? '';

    return is_string($token) && preg_match('/^[0-9a-f]{24}$/', $token) === 1
        ? GLPI_TMP_DIR . '/grcmanager-supplier-' . $token . '.csv'
        : '';
};
$forget = static function () use ($key, $pendingFile): void {
    $real = realpath($pendingFile()) ?: '';
    if ($real !== '' && str_starts_with($real, (realpath(GLPI_TMP_DIR) ?: '/nonexistent') . DIRECTORY_SEPARATOR)) {
        // Chemin créé par le serveur (nom aléatoire gardé en session) et vérifié sous GLPI_TMP_DIR.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($real);
    }
    unset($_SESSION[$key], $_SESSION[$key . '_file']);
};

if (isset($_GET['export'])) {
    $evaluation->check($id, READ);
    $content = SupplierEvaluationFile::export($evaluation);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . SupplierEvaluationFile::fileName($evaluation) . '"');
    header('X-Content-Type-Options: nosniff');
    echo $content;

    return;
}

$evaluation->check($id, UPDATE);
if (!SupplierEvaluationFile::canImport($evaluation)) {
    throw new AccessDeniedHttpException();
}
$back = SupplierEvaluation::getFormURLWithID($id);

if (isset($_POST['cancel'])) {
    $forget();
    Html::redirect($back);
}

if (isset($_POST['upload'])) {
    $forget();
    $file = $_FILES['answer_file'] ?? null;
    if (
        !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
    ) {
        Session::addMessageAfterRedirect(htmlescape(__('The file could not be received.', 'grcmanager')), false, ERROR);
        Html::redirect($back);
    }
    if ((int) $file['size'] > SupplierAnswerFile::MAX_BYTES) {
        Session::addMessageAfterRedirect(
            htmlescape(SupplierEvaluationFile::errorMessage(SupplierAnswerFile::ERROR_TOO_LARGE)),
            false,
            ERROR
        );
        Html::redirect($back);
    }
    $token = bin2hex(random_bytes(12));
    $stored = GLPI_TMP_DIR . '/grcmanager-supplier-' . $token . '.csv';
    if (!move_uploaded_file($file['tmp_name'], $stored)) {
        Session::addMessageAfterRedirect(htmlescape(__('The file could not be received.', 'grcmanager')), false, ERROR);
        Html::redirect($back);
    }
    $name = EvidenceUploadRules::safeName(basename((string) ($file['name'] ?? ''))) ?? 'questionnaire.csv';
    $_SESSION[$key] = ['evaluation' => $id, 'name' => $name];
    $_SESSION[$key . '_file'] = $token;
}

$pending = $_SESSION[$key] ?? null;
$pendingPath = $pendingFile();
$valid = is_array($pending) && (int) ($pending['evaluation'] ?? 0) === $id && $pendingPath !== ''
    && str_starts_with(realpath($pendingPath) ?: '', (realpath(GLPI_TMP_DIR) ?: '/nonexistent') . DIRECTORY_SEPARATOR)
    && is_readable($pendingPath);
if (!$valid) {
    $forget();
    if (isset($_POST['confirm'])) {
        throw new BadRequestHttpException();
    }
    Html::redirect($back);
}

$parsed = SupplierEvaluationFile::analyse($evaluation, (string) file_get_contents($pendingPath));

if (isset($_POST['confirm'])) {
    if ($parsed['error'] !== null) {
        $forget();
        throw new BadRequestHttpException();
    }
    // Pièces justificatives déposées par le RSSI, rattachées aux questions par leur nom.
    $files = [];
    $upload = $_FILES['evidence_files'] ?? null;
    if (is_array($upload) && is_array($upload['error'] ?? null)) {
        foreach ($upload['error'] as $index => $phpError) {
            $tmp = $upload['tmp_name'][$index] ?? null;
            $name = $upload['name'][$index] ?? '';
            if ($phpError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($phpError !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp) || !is_string($name)) {
                Session::addMessageAfterRedirect(
                    htmlescape(__('The file could not be received.', 'grcmanager')),
                    false,
                    ERROR
                );
                continue;
            }
            $files[] = ['path' => $tmp, 'name' => basename($name)];
        }
    }
    $result = SupplierEvaluationFile::apply(
        $evaluation,
        $parsed,
        $pendingPath,
        (string) $pending['name'],
        $files
    );
    $forget();
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('%1$d answer(s) imported from the supplier file, %2$d document(s) attached.', 'grcmanager'),
        $result['answers'],
        $result['documents']
    )));
    foreach ($result['unmatched'] as $name) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('The document "%s" matches no question of the file: it was not added.', 'grcmanager'),
            $name
        )), false, WARNING);
    }
    Html::redirect($back);
}

if ($parsed['error'] !== null) {
    $forget();
}

Html::header(
    SupplierEvaluationFile::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    SupplierRisk::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('supplierevaluationfile');
TemplateRenderer::getInstance()->display(
    '@grcmanager/supplier_evaluation_import.html.twig',
    SupplierEvaluationFile::previewData($evaluation, $parsed, (string) $pending['name'])
);
Html::footer();

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
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\SupplierEvaluation;
use GlpiPlugin\Grcmanager\SupplierEvaluationAnswer;
use GlpiPlugin\Grcmanager\SupplierRisk;

global $CFG_GLPI;

// Issue #864 : évaluation d'un fournisseur avec un modèle de questionnaire, ses réponses et leurs
// pièces justificatives. Droits du risque fournisseur évalué (droit du registre, entité du risque) :
// lire pour consulter, modifier le risque pour répondre. Le jeton CSRF est contrôlé par GLPI.
$item = new SupplierEvaluation();

if (isset($_POST['add'])) {
    $risk = new SupplierRisk();
    $riskId = (int) ($_POST[SupplierQuestionnaireTables::RISK_FK] ?? 0);
    if ($riskId <= 0 || !$risk->getFromDB($riskId)) {
        throw new BadRequestHttpException();
    }
    $risk->check($riskId, UPDATE);
    $input = $_POST;
    $input['entities_id'] = (int) $risk->fields['entities_id'];
    $item->check(-1, CREATE, $input);
    $newID = $item->add($input);
    if ($newID) {
        Html::redirect(SupplierEvaluation::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $riskId = (int) $item->fields[SupplierQuestionnaireTables::RISK_FK];
    $item->delete($_POST, true);
    Html::redirect(SupplierRisk::getFormURLWithID($riskId));
} elseif (isset($_POST['update'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['complete']) || isset($_POST['reopen'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $completing = isset($_POST['complete']);
    if ($completing ? $item->complete() : $item->reopen()) {
        Session::addMessageAfterRedirect(htmlescape($completing
            ? __('Evaluation completed.', 'grcmanager')
            : __('Evaluation reopened.', 'grcmanager')));
    }
    Html::back();
} elseif (isset($_POST['save_answers'])) {
    $id = (int) ($_POST[SupplierEvaluation::FK] ?? 0);
    if ($id <= 0 || !$item->getFromDB($id)) {
        throw new BadRequestHttpException();
    }
    $item->check($id, UPDATE);
    if ($item->isCompleted()) {
        throw new AccessDeniedHttpException();
    }
    SupplierEvaluationAnswer::saveAnswers(
        $item,
        is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [],
        is_array($_POST['comments'] ?? null) ? $_POST['comments'] : [],
        is_array($_POST['detach'] ?? null) ? $_POST['detach'] : []
    );
    // Pièces justificatives : un fichier au plus par question, mêmes contrôles que les preuves.
    $files = $_FILES['evidence'] ?? null;
    $attached = 0;
    if (is_array($files) && is_array($files['error'] ?? null)) {
        foreach ($files['error'] as $answerId => $phpError) {
            $phpError = is_int($phpError) ? $phpError : UPLOAD_ERR_NO_FILE;
            if ($phpError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $size = $files['size'][$answerId] ?? 0;
            $error = EvidenceUploadRules::uploadError(
                $phpError,
                is_int($size) ? $size : 0,
                (int) $CFG_GLPI['document_max_size'] * 1024 * 1024
            );
            $tmp = $files['tmp_name'][$answerId] ?? null;
            $name = $files['name'][$answerId] ?? '';
            if ($error !== null) {
                Session::addMessageAfterRedirect(htmlescape(ControlTest::uploadErrorMessage($error)), false, ERROR);
            } elseif (is_string($tmp) && is_uploaded_file($tmp)) {
                $documentsId = SupplierEvaluationAnswer::attachEvidence(
                    $item,
                    (int) $answerId,
                    $tmp,
                    is_string($name) ? $name : ''
                );
                $attached += $documentsId > 0 ? 1 : 0;
            } else {
                Session::addMessageAfterRedirect(
                    htmlescape(ControlTest::uploadErrorMessage(EvidenceUploadRules::ERROR_FAILED)),
                    false,
                    ERROR
                );
            }
        }
    }
    $message = $attached > 0
        ? sprintf(
            _n('Answers saved, %d document added.', 'Answers saved, %d documents added.', $attached, 'grcmanager'),
            $attached
        )
        : __('Answers saved.', 'grcmanager');
    Session::addMessageAfterRedirect(htmlescape($message));
    if ($_POST['save_answers'] === 'complete' && $item->getFromDB($id) && $item->complete()) {
        Session::addMessageAfterRedirect(htmlescape(__('Evaluation completed.', 'grcmanager')));
    }
    Html::back();
} else {
    Session::checkRight(SupplierEvaluation::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        // Une évaluation se crée depuis l'onglet « Questionnaires » de son risque fournisseur.
        Html::redirect(SupplierRisk::getSearchURL());
    }
    $item->check($id, READ);

    Html::header(
        SupplierEvaluation::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Risk::class,
        SupplierRisk::class
    );

    $item->display(['id' => $id]);

    Html::footer();
}

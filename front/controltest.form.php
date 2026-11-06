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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

global $CFG_GLPI;

// Issue #380 : onglet « Tests d'efficacité » d'une mesure (plan de test, enregistrement et
// suppression d'un test). Tout passe par le droit de modifier la mesure, jamais par le seul droit
// global du plugin. Pas de fiche propre : retour à l'onglet de la mesure.
Session::checkRight(Control::$rightname, UPDATE);

$controlsId = (int) ($_POST['plugin_grcmanager_controls_id'] ?? 0);

if (isset($_POST['save_plan'])) {
    $control = new Control();
    $control->check($controlsId, UPDATE);
    $control->update([
        'id'                    => $controlsId,
        'test_frequency_months' => $_POST['test_frequency_months'] ?? 0,
        'test_method'           => $_POST['test_method'] ?? '',
        'users_id_tester'       => $_POST['users_id_tester'] ?? 0,
        '_grcmanager_from_test' => true,
    ]);
} elseif (isset($_POST['record_check'])) {
    // Issue #380 (#162) : résultat actuel d'un contrôle technique automatique, recalculé ici.
    if (!ControlTest::canEditControl($controlsId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $control = new Control();
    $control->getFromDB($controlsId);
    ControlTest::recordTechnicalCheck($control, (string) $_POST['record_check']);
} elseif (isset($_POST['add'])) {
    if (!ControlTest::canEditControl($controlsId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $documentsId = (int) ($_POST['documents_id'] ?? 0);
    // Envoi direct d'une preuve : un seul fichier, jamais en plus d'un document choisi.
    $file = $_FILES['evidence_file'] ?? null;
    $uploaded = 0;
    if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $control = new Control();
        $control->getFromDB($controlsId);
        // Mêmes droits que l'onglet Documents de la mesure.
        if (!$control->canAddItem('Document') || !Document::canCreate()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $error = EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            (int) $CFG_GLPI['document_max_size'] * 1024 * 1024
        );
        if ($documentsId > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Choose an existing document or send a file, not both.', 'grcmanager')),
                false,
                ERROR
            );
        } elseif ($error !== null) {
            Session::addMessageAfterRedirect(htmlescape(ControlTest::uploadErrorMessage($error)), false, ERROR);
        } elseif (is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])) {
            $uploaded = ControlTest::storeEvidenceFile($control, $file['tmp_name'], (string) ($file['name'] ?? ''));
        } else {
            Session::addMessageAfterRedirect(
                htmlescape(ControlTest::uploadErrorMessage(EvidenceUploadRules::ERROR_FAILED)),
                false,
                ERROR
            );
        }
        if ($uploaded <= 0) {
            Html::redirect(ControlTest::getControlTabURL($controlsId));
        }
        $documentsId = $uploaded;
        $_POST['documents_id'] = $uploaded;
    }
    // Preuve : un document joint à cette mesure (sinon introuvable), lisible par l'utilisateur
    // (la mesure est globale, le document appartient à une entité). Revérifié par prepareInputForAdd().
    if ($documentsId > 0 && $uploaded === 0) {
        if (!Evidence::isDocumentLinked(Control::class, $controlsId, $documentsId)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        if (!Evidence::canReadDocument($documentsId)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
    }
    if (!(new ControlTest())->add($_POST) && $uploaded > 0) {
        // Test refusé (constat manquant...) : le fichier envoyé pour lui ne reste pas.
        (new Document())->delete(['id' => $uploaded], true);
    }
} elseif (isset($_POST['purge'])) {
    $test = new ControlTest();
    $test->check((int) ($_POST['id'] ?? 0), PURGE);
    $controlsId = (int) $test->fields['plugin_grcmanager_controls_id'];
    $test->delete(['id' => $test->getID()], true);
}

Html::redirect(ControlTest::getControlTabURL($controlsId));

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
use GlpiPlugin\Grcmanager\SupplierQuestionnaireQuestion;
use GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate;

// Issue #864 : modèle de questionnaire fournisseur et ses questions. Chaque action vérifie le droit
// du registre des risques fournisseurs et l'entité du modèle (check() du cœur) ; le jeton CSRF est
// contrôlé par GLPI sur tout POST.
$item = new SupplierQuestionnaireTemplate();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(SupplierQuestionnaireTemplate::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete($_POST, true);
    Html::redirect(SupplierQuestionnaireTemplate::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['duplicate'])) {
    $item->check((int) ($_POST['id'] ?? 0), READ);
    Session::checkRight(SupplierQuestionnaireTemplate::$rightname, CREATE);
    $copyId = $item->duplicate();
    if ($copyId > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Template copied: adapt its questions.', 'grcmanager')));
        Html::redirect(SupplierQuestionnaireTemplate::getFormURLWithID($copyId));
    }
    Html::back();
} elseif (isset($_POST['save_questions'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !$item->getFromDB($id)) {
        throw new BadRequestHttpException();
    }
    $item->check($id, UPDATE);
    $result = SupplierQuestionnaireQuestion::saveQuestions(
        $item,
        is_array($_POST['questions'] ?? null) ? $_POST['questions'] : [],
        is_array($_POST['new_questions'] ?? null) ? $_POST['new_questions'] : []
    );
    Session::addMessageAfterRedirect(htmlescape(array_sum($result) > 0
        ? sprintf(
            __('Questions saved: %1$d added, %2$d changed, %3$d deleted.', 'grcmanager'),
            $result['added'],
            $result['changed'],
            $result['deleted']
        )
        : __('No change to the questions.', 'grcmanager')));
    Html::back();
} else {
    Session::checkRight(SupplierQuestionnaireTemplate::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $item->check($id, READ);
    } elseif (!SupplierQuestionnaireTemplate::canCreate()) {
        throw new AccessDeniedHttpException();
    }

    Html::header(
        SupplierQuestionnaireTemplate::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\Risk::class,
        \GlpiPlugin\Grcmanager\SupplierRisk::class
    );

    $item->display(['id' => $id]);

    Html::footer();
}

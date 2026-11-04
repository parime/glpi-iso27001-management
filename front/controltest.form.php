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

include('../../../inc/includes.php');

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
} elseif (isset($_POST['add'])) {
    if (!ControlTest::canEditControl($controlsId)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    // Preuve : un document joint à cette mesure (sinon introuvable), lisible par l'utilisateur
    // (la mesure est globale, le document appartient à une entité). Revérifié par prepareInputForAdd().
    $documentsId = (int) ($_POST['documents_id'] ?? 0);
    if ($documentsId > 0) {
        if (!Evidence::isDocumentLinked(Control::class, $controlsId, $documentsId)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        if (!Evidence::canReadDocument($documentsId)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
    }
    (new ControlTest())->add($_POST);
} elseif (isset($_POST['purge'])) {
    $test = new ControlTest();
    $test->check((int) ($_POST['id'] ?? 0), PURGE);
    $controlsId = (int) $test->fields['plugin_grcmanager_controls_id'];
    $test->delete(['id' => $test->getID()], true);
}

Html::redirect(ControlTest::getControlTabURL($controlsId));

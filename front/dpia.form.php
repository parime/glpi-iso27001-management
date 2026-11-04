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

use GlpiPlugin\Grcmanager\Dpia;
use GlpiPlugin\Grcmanager\ProcessingActivity;

include('../../../inc/includes.php');

// Issue #375 : AIPD d'une fiche du registre des traitements. Droits et entité de la fiche (check() du
// cœur) ; le jeton CSRF est contrôlé par GLPI sur tout POST.
Session::checkRight(Dpia::RIGHTNAME, UPDATE);

$activityId = (int) ($_POST[Dpia::ACTIVITY_FK] ?? 0);
$activity = new ProcessingActivity();
$activity->check($activityId, UPDATE);

if (isset($_POST['save'])) {
    Dpia::saveForm($activityId, $_POST);
} else {
    $dpia = Dpia::forActivity($activityId);
    if ($dpia === null) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    if (isset($_POST['approve'])) {
        $dpia->approve();
    } elseif (isset($_POST['reopen'])) {
        $dpia->reopen();
    } elseif (isset($_POST['apply_record_status'])) {
        Dpia::applyRecordStatus($activity);
    } elseif (isset($_POST['send_to_register'])) {
        $dpia->sendToRegister($activity);
    }
}

Html::back();

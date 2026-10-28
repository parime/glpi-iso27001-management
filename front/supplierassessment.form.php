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

/**
 * Issue #127 : enregistrement du questionnaire de sécurité d'un risque fournisseur.
 */

Session::checkRight(PluginGrcmanagerSupplierRisk::RIGHTNAME, UPDATE);

$risk = new PluginGrcmanagerSupplierRisk();
$id = (int) ($_POST['id'] ?? 0);
if (!isset($_POST['save_questionnaire']) || !$risk->getFromDB($id) || !$risk->can($id, UPDATE)) {
    Html::back();
}

$answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
if (PluginGrcmanagerSupplierAssessment::save($risk, $answers)) {
    Session::addMessageAfterRedirect(__('Security questionnaire saved.', 'grcmanager'));
}
Html::back();

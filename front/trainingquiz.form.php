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

include('../../../inc/includes.php');

/**
 * Issue #139 : joindre ou retirer la copie de quiz d'un participant (onglet « Copies de quiz »
 * de la session). Il faut pouvoir modifier la session.
 */

Session::checkRight(PluginGrcmanagerTraining::RIGHTNAME, UPDATE);

$participation = PluginGrcmanagerTrainingQuiz::getParticipation((int) ($_POST['participations_id'] ?? 0));
if ($participation === null) {
    Html::back();
}
(new PluginGrcmanagerTraining())->check($participation['trainings_id'], UPDATE);

if (isset($_POST['remove'])) {
    PluginGrcmanagerTrainingQuiz::deleteCopy($participation['id']);
    Html::back();
}

$file = $_FILES['quiz'] ?? null;
if (!isset($_POST['upload']) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Session::addMessageAfterRedirect(__('Choose the file of the copy.', 'grcmanager'), false, ERROR);
    Html::back();
}

// Même convention que l'envoi de fichiers du cœur : préfixe unique dans GLPI_TMP_DIR, retiré du
// nom affiché par Document::add() ; le type de fichier est contrôlé par GLPI (types autorisés).
$prefix = uniqid('', true);
$tmpName = $prefix . preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $file['name']));
if (!move_uploaded_file((string) $file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
    Html::back();
}
if (PluginGrcmanagerTrainingQuiz::attach($participation['id'], $tmpName, $prefix) <= 0) {
    Session::addMessageAfterRedirect(
        __('The copy could not be attached (file type not allowed?).', 'grcmanager'),
        false,
        ERROR
    );
}

Html::back();

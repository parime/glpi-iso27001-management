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

// Issue #377, troisième étape : temps de reprise mesuré d'un processus (onglet « Stratégie de
// reprise », ou salle de crise). Ajouter ou retirer un test exige de pouvoir modifier le processus
// (droit de la continuité et entité) ; le rattacher à un exercice exige de pouvoir lire son incident.
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, UPDATE);
$process = new \GlpiPlugin\Grcmanager\BusinessProcess();
$recovery = new \GlpiPlugin\Grcmanager\BusinessProcessRecovery();

if (isset($_POST['add'])) {
    $process->check((int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0), UPDATE);
    $exerciseId = (int) ($_POST[\GlpiPlugin\Grcmanager\BusinessProcessRecovery::EXERCISE_FIELD] ?? 0);
    // Exercice illisible (droit des incidents, entité) : introuvable pour la personne.
    if ($exerciseId > 0 && \GlpiPlugin\Grcmanager\BusinessProcessRecovery::readableExercise($exerciseId) === null) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $recovery->add([
        'plugin_grcmanager_businessprocesses_id'                   => (int) $process->getID(),
        \GlpiPlugin\Grcmanager\BusinessProcessRecovery::EXERCISE_FIELD => $exerciseId,
        'test_date'                                                => (string) ($_POST['test_date'] ?? ''),
        'measured_minutes'                                         => $_POST['measured_minutes'] ?? 0,
        'comment'                                                  => (string) ($_POST['comment'] ?? ''),
    ]);
} elseif (isset($_POST['purge'])) {
    if (!$recovery->getFromDB((int) ($_POST['id'] ?? 0))) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $process->check((int) $recovery->fields['plugin_grcmanager_businessprocesses_id'], UPDATE);
    $recovery->delete(['id' => (int) $recovery->getID()], true);
}

Html::back();

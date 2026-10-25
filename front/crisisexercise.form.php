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

include('../../../inc/includes.php');

// Issue #168 : salle de crise d'un exercice (étapes chronométrées, fin et rapport).
if (!PluginGrcmanagerCrisisExercise::canView()) {
    Html::displayRightError();
}
$exercise = new PluginGrcmanagerCrisisExercise();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$incident = $exercise->getFromDB($id) ? $exercise->getIncident() : null;
if ($incident === null || !$incident->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['step_done']) || isset($_POST['finish'])) {
    if (!$incident->canUpdateItem()) {
        Html::displayRightError();
    }
    if (isset($_POST['step_done'])) {
        $exercise->markStepDone((int) $_POST['step_done']);
    } elseif ($exercise->finish() > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Exercice terminé : le rapport est archivé dans les documents de l\'incident d\'exercice.',
            'grcmanager'
        )));
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(__('Le rapport n\'a pas pu être généré.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::redirect(PluginGrcmanagerCrisisExercise::getFormURLWithID($id));
}

Html::header(
    PluginGrcmanagerCrisisExercise::getTypeName(1),
    PluginGrcmanagerCrisisExercise::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerCrisisExercise::class
);
TemplateRenderer::getInstance()->display('@grcmanager/crisis_room.html.twig', $exercise->collect() + [
    'can_act'  => $incident->canUpdateItem(),
    'action'   => PluginGrcmanagerCrisisExercise::getFormURL(),
    'list_url' => PluginGrcmanagerCrisisExercise::getSearchURL(),
]);
Html::footer();

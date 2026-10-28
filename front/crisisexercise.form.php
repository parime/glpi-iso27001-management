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
// Issue #185 : la même salle sert de cellule de crise sur un incident réel.
Session::checkRightsOr(
    PluginGrcmanagerCrisisExercise::RIGHTNAME,
    [PluginGrcmanagerSecurityIncident::READALL, PluginGrcmanagerSecurityIncident::READMY]
);

if (isset($_POST['activate'])) {
    $incident = new PluginGrcmanagerSecurityIncident();
    if (!$incident->getFromDB((int) ($_POST['incident'] ?? 0)) || !$incident->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    if (!$incident->canUpdateItem()) {
        Html::displayRightError();
    }
    $cellId = PluginGrcmanagerCrisisExercise::activate($incident, (string) ($_POST['scenario'] ?? ''));
    if ($cellId > 0) {
        Html::redirect(PluginGrcmanagerCrisisExercise::getFormURLWithID($cellId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('The crisis team could not be activated.', 'grcmanager')),
        false,
        ERROR
    );
    Html::back();
}

$exercise = new PluginGrcmanagerCrisisExercise();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$incident = $exercise->getFromDB($id) ? $exercise->getIncident() : null;
if ($incident === null || !$incident->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['step_done']) || isset($_POST['finish']) || isset($_POST['restored'])) {
    if (!$incident->canUpdateItem()) {
        Html::displayRightError();
    }
    if (isset($_POST['step_done'])) {
        $exercise->markStepDone((int) $_POST['step_done']);
    } elseif (isset($_POST['restored'])) {
        $exercise->markRestored((string) $_POST['restored']);
    } elseif ($exercise->finish() > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Finished: the report is archived in the incident documents.',
            'grcmanager'
        )));
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(__('The report could not be generated.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::redirect(PluginGrcmanagerCrisisExercise::getFormURLWithID($id));
}
if (isset($_POST['journal'])) {
    if (!$incident->canAddFollowups()) {
        Html::displayRightError();
    }
    $exercise->addJournalEntry((string) $_POST['journal']);
    Html::redirect(PluginGrcmanagerCrisisExercise::getFormURLWithID($id) . '#grc-crisis-journal');
}

Html::header(
    $exercise->isDrill() ? PluginGrcmanagerCrisisExercise::getTypeName(1) : __('Crisis team', 'grcmanager'),
    PluginGrcmanagerCrisisExercise::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerAccessReview::class,
    PluginGrcmanagerCrisisExercise::class
);
TemplateRenderer::getInstance()->display('@grcmanager/crisis_room.html.twig', $exercise->collect() + [
    'can_act'     => $incident->canUpdateItem(),
    'can_journal' => $incident->canAddFollowups(),
    'action'      => PluginGrcmanagerCrisisExercise::getFormURL(),
    'list_url'    => PluginGrcmanagerCrisisExercise::getSearchURL(),
]);
Html::footer();

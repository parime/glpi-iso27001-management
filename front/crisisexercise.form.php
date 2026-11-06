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

// Issue #168 : salle de crise d'un exercice (étapes chronométrées, fin et rapport).
// Issue #185 : la même salle sert de cellule de crise sur un incident réel.
Session::checkRightsOr(
    \GlpiPlugin\Grcmanager\CrisisExercise::RIGHTNAME,
    [PluginGrcmanagerSecurityIncident::READALL, PluginGrcmanagerSecurityIncident::READMY]
);

if (isset($_POST['activate'])) {
    $incident = new PluginGrcmanagerSecurityIncident();
    if (!$incident->getFromDB((int) ($_POST['incident'] ?? 0)) || !$incident->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    if (!$incident->canUpdateItem()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $cellId = \GlpiPlugin\Grcmanager\CrisisExercise::activate($incident, (string) ($_POST['scenario'] ?? ''));
    if ($cellId > 0) {
        Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($cellId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('The crisis team could not be activated.', 'grcmanager')),
        false,
        ERROR
    );
    Html::back();
}

$exercise = new \GlpiPlugin\Grcmanager\CrisisExercise();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$incident = $exercise->getFromDB($id) ? $exercise->getIncident() : null;
if ($incident === null || !$incident->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['step_done']) || isset($_POST['finish']) || isset($_POST['restored'])) {
    if (!$incident->canUpdateItem()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
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
    Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($id));
}
if (isset($_POST['journal'])) {
    if (!$incident->canAddFollowups()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $exercise->addJournalEntry((string) $_POST['journal']);
    Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($id) . '#grc-crisis-journal');
}

Html::header(
    $exercise->isDrill() ? \GlpiPlugin\Grcmanager\CrisisExercise::getTypeName(1) : __('Crisis team', 'grcmanager'),
    \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\CrisisExercise::class
);
// Issue #377, troisième étape : temps de reprise des processus mesurés pendant l'exercice (droit de la
// continuité ; seuls les processus lisibles sont nommés, seuls ceux modifiables sont proposés).
$data = $exercise->collect();
// Possible aussi après la fin de l'exercice (retour d'expérience).
$canRecord = \GlpiPlugin\Grcmanager\BusinessProcess::canUpdate();
TemplateRenderer::getInstance()->display('@grcmanager/crisis_room.html.twig', $data + [
    'show_processes'      => \GlpiPlugin\Grcmanager\BusinessProcess::canView(),
    'process_recoveries'  => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::forExercise($exercise),
    'recovery_processes'  => $canRecord ? \GlpiPlugin\Grcmanager\BusinessProcessRecovery::processChoices() : [],
    'recovery_url'        => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::getFormURL(),
    'recovery_minutes'    => (int) $data['exercise']['duration'],
    'can_act'     => $incident->canUpdateItem(),
    'can_journal' => $incident->canAddFollowups(),
    'action'      => \GlpiPlugin\Grcmanager\CrisisExercise::getFormURL(),
    'list_url'    => \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL(),
]);
Html::footer();

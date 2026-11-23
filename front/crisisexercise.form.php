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
    if (isset($_POST['finish']) && \GlpiPlugin\Grcmanager\CrisisExerciseReview::isValidated($exercise)) {
        // Issue #732 : le rapport d'un bilan validé ne se régénère qu'après retour en brouillon.
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
// Issue #732 : bilan après exercice (objectifs, constats, plan d'amélioration) et validation.
$review = \GlpiPlugin\Grcmanager\CrisisExerciseReview::class;
$reviewActions = ['add_objective', 'evaluate_objective', 'delete_objective', 'add_finding', 'delete_finding',
    'create_action'];
if (array_intersect($reviewActions, array_keys($_POST)) !== []) {
    if (!$incident->canUpdateItem()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $anchor = '#grc-crisis-findings';
    if (isset($_POST['add_objective'])) {
        $done = $review::addObjectives($exercise, [(string) ($_POST['objective'] ?? '')]) > 0;
        $anchor = '#grc-crisis-objectives';
    } elseif (isset($_POST['evaluate_objective'])) {
        $done = $review::evaluateObjective(
            $exercise,
            (int) $_POST['evaluate_objective'],
            (string) ($_POST['outcome'] ?? ''),
            (string) ($_POST['comment'] ?? '')
        );
        $anchor = '#grc-crisis-objectives';
    } elseif (isset($_POST['delete_objective'])) {
        $done = $review::deleteObjective($exercise, (int) $_POST['delete_objective']);
        $anchor = '#grc-crisis-objectives';
    } elseif (isset($_POST['add_finding'])) {
        $done = $review::addFinding(
            $exercise,
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (string) ($_POST['severity'] ?? '')
        ) > 0;
    } elseif (isset($_POST['delete_finding'])) {
        $done = $review::deleteFinding($exercise, (int) $_POST['delete_finding']);
    } else {
        // Créée seulement sur ce clic : jamais d'action d'amélioration automatique.
        $done = $review::createAction(
            $exercise,
            (int) $_POST['create_action'],
            (string) ($_POST['action_type'] ?? ''),
            (int) ($_POST['users_id'] ?? 0),
            (string) ($_POST['due_date'] ?? ''),
            (int) ($_POST['plugin_grcmanager_risks_id'] ?? 0)
        ) > 0;
        if ($done) {
            Session::addMessageAfterRedirect(htmlescape(__('Improvement action created.', 'grcmanager')));
        }
    }
    if (!$done) {
        Session::addMessageAfterRedirect(
            htmlescape(__('The review could not be changed.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($id) . $anchor);
}
if (isset($_POST['validate_review']) || isset($_POST['reopen_review'])) {
    // Droit dédié « Valider les bilans d'exercice » (continuité d'activité).
    if (!$review::canValidate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if (isset($_POST['validate_review'])) {
        $refusal = $review::validate($exercise, (string) ($_POST['comment'] ?? ''));
        if ($refusal !== '') {
            Session::addMessageAfterRedirect(htmlescape($review::refusalMessage($refusal)), false, ERROR);
        } elseif ($exercise->finish() > 0) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Review validated: the validated report is archived in the incident documents.',
                'grcmanager'
            )));
        }
    } elseif ($review::reopen($exercise, (string) ($_POST['reason'] ?? ''))) {
        Session::addMessageAfterRedirect(htmlescape(__('The review is back to draft.', 'grcmanager')));
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(__('Give the reason for returning the review to draft.', 'grcmanager')),
            false,
            ERROR
        );
    }
    Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($id) . '#grc-crisis-validation');
}
// Issue #733 : type d'exercice, modifiable tant que le bilan n'est pas validé.
if (isset($_POST['set_exercise_type'])) {
    if (!$incident->canUpdateItem()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if (!$exercise->setExerciseType($_POST['exercise_type'] ?? '')) {
        Session::addMessageAfterRedirect(
            htmlescape(__('The exercise type could not be changed.', 'grcmanager')),
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

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\CrisisExercise::class,
    $exercise->isDrill() ? \GlpiPlugin\Grcmanager\CrisisExercise::getTypeName(1) : __('Crisis team', 'grcmanager'),
    \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL()
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
    // Issue #733 : type d'exercice et perte de données mesurée.
    'types'               => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::typeChoices(
        (string) $data['exercise']['type_key']
    ),
    'result_labels'       => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::resultLabels(),
    // Issue #732 : bilan après exercice.
    'outcomes'            => $review::getOutcomes(),
    'severities'          => \GlpiPlugin\Grcmanager\Nonconformity::getSeverities(),
    'action_types'        => $incident->canUpdateItem() ? $review::getActionTypes() : [],
    'risk_choices'        => $incident->canUpdateItem() ? $review::riskChoices() : [],
    'can_validate'        => $review::canValidate(),
    'user_entity'         => (int) $exercise->fields['entities_id'],
    'can_act'     => $incident->canUpdateItem(),
    'can_journal' => $incident->canAddFollowups(),
    'action'      => \GlpiPlugin\Grcmanager\CrisisExercise::getFormURL(),
    'list_url'    => \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL(),
]);
Html::footer();

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
use GlpiPlugin\Grcmanager\Services\Exercise\CrisisExercise;

include('../../../inc/includes.php');

// Issue #168 : choix du scénario, lancement et historique des exercices de crise.
Session::checkRightsOr(
    \GlpiPlugin\Grcmanager\CrisisExercise::RIGHTNAME,
    [PluginGrcmanagerSecurityIncident::READALL, PluginGrcmanagerSecurityIncident::READMY]
);

if (isset($_POST['launch'])) {
    if (!\GlpiPlugin\Grcmanager\CrisisExercise::canCreate()) {
        Html::displayRightError();
    }
    $exerciseId = \GlpiPlugin\Grcmanager\CrisisExercise::launch((string) $_POST['launch']);
    if ($exerciseId > 0) {
        Html::redirect(\GlpiPlugin\Grcmanager\CrisisExercise::getFormURLWithID($exerciseId));
    }
    Session::addMessageAfterRedirect(htmlescape(__('The exercise could not be started.', 'grcmanager')), false, ERROR);
    Html::back();
}

$labels = \GlpiPlugin\Grcmanager\CrisisExercise::getStepLabels();
$scenarios = [];
foreach (\GlpiPlugin\Grcmanager\CrisisExercise::getScenarios() as $key => $scenario) {
    $steps = [];
    foreach (CrisisExercise::orderedSteps($key) as [$step, $target]) {
        $steps[] = ['label' => $labels[$step] ?? $step, 'target' => $target];
    }
    $scenarios[] = $scenario + ['key' => $key, 'steps' => $steps];
}

Html::header(
    \GlpiPlugin\Grcmanager\CrisisExercise::getTypeName(Session::getPluralNumber()),
    \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\CrisisExercise::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\CrisisExercise::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('crisisexercise');
TemplateRenderer::getInstance()->display('@grcmanager/crisis_exercises.html.twig', [
    'scenarios'  => $scenarios,
    'history'    => \GlpiPlugin\Grcmanager\CrisisExercise::history(),
    'can_launch' => \GlpiPlugin\Grcmanager\CrisisExercise::canCreate(),
    'action'     => \GlpiPlugin\Grcmanager\CrisisExercise::getSearchURL(),
]);
Html::footer();

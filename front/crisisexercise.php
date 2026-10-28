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
    PluginGrcmanagerCrisisExercise::RIGHTNAME,
    [PluginGrcmanagerSecurityIncident::READALL, PluginGrcmanagerSecurityIncident::READMY]
);

if (isset($_POST['launch'])) {
    if (!PluginGrcmanagerCrisisExercise::canCreate()) {
        Html::displayRightError();
    }
    $exerciseId = PluginGrcmanagerCrisisExercise::launch((string) $_POST['launch']);
    if ($exerciseId > 0) {
        Html::redirect(PluginGrcmanagerCrisisExercise::getFormURLWithID($exerciseId));
    }
    Session::addMessageAfterRedirect(htmlescape(__('The exercise could not be started.', 'grcmanager')), false, ERROR);
    Html::back();
}

$labels = PluginGrcmanagerCrisisExercise::getStepLabels();
$scenarios = [];
foreach (PluginGrcmanagerCrisisExercise::getScenarios() as $key => $scenario) {
    $steps = [];
    foreach (CrisisExercise::orderedSteps($key) as [$step, $target]) {
        $steps[] = ['label' => $labels[$step] ?? $step, 'target' => $target];
    }
    $scenarios[] = $scenario + ['key' => $key, 'steps' => $steps];
}

Html::header(
    PluginGrcmanagerCrisisExercise::getTypeName(Session::getPluralNumber()),
    PluginGrcmanagerCrisisExercise::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerAccessReview::class,
    PluginGrcmanagerCrisisExercise::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerAccessReview::class, PluginGrcmanagerCrisisExercise::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('crisisexercise');
TemplateRenderer::getInstance()->display('@grcmanager/crisis_exercises.html.twig', [
    'scenarios'  => $scenarios,
    'history'    => PluginGrcmanagerCrisisExercise::history(),
    'can_launch' => PluginGrcmanagerCrisisExercise::canCreate(),
    'action'     => PluginGrcmanagerCrisisExercise::getSearchURL(),
]);
Html::footer();

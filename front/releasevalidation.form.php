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

use GlpiPlugin\Grcmanager\ReleaseValidation;

// Issue #777 : liste de contrôle et décision de mise en production d'un changement, depuis l'onglet
// « Validation sécurité de mise en production » du changement (seul écran qui poste ici).
Session::checkRight(ReleaseValidation::$rightname, READ);

// can() et non getFromDB() : le changement doit être visible par la personne (droit natif ET
// entité), et lié à au moins une application.
$changesId = (int) ($_POST['changes_id'] ?? 0);
$change = new Change();
if ($changesId <= 0 || !$change->can($changesId, READ) || !ReleaseValidation::concerns($change)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$error = null;
$done = '';
if (isset($_POST['save_checklist'])) {
    Session::checkRight(
        ReleaseValidation::$rightname,
        ReleaseValidation::getForChange($changesId) === null ? CREATE : UPDATE
    );
    $error = ReleaseValidation::saveChecklist($changesId, $_POST);
    $done = __('Checklist saved.', 'grcmanager');
} elseif (isset($_POST['decision'])) {
    // Décision : droit dédié ; l'auteur et la date viennent de la session.
    Session::checkRight(ReleaseValidation::$rightname, ReleaseValidation::DECIDE);
    $error = ReleaseValidation::decide(
        $changesId,
        (string) $_POST['decision'],
        (string) ($_POST['validator_role'] ?? ''),
        (string) ($_POST['decision_comment'] ?? '')
    );
    $done = __('Decision recorded.', 'grcmanager');
} elseif (isset($_POST['reopen'])) {
    Session::checkRight(ReleaseValidation::$rightname, ReleaseValidation::DECIDE);
    $error = ReleaseValidation::reopen($changesId);
    $done = __('Decision reopened.', 'grcmanager');
} else {
    Html::back();
}

Session::addMessageAfterRedirect(htmlescape($error ?? $done), false, $error === null ? INFO : ERROR);
Html::back();

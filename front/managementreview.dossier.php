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

// Issue #542 : génère le dossier préparatoire d'une revue de direction (9.3.2) sur la période
// choisie, l'archive sur la revue et revient sur l'onglet du dossier. Il faut pouvoir modifier la
// revue (un document y est joint).
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\ManagementReviewDossier;
use GlpiPlugin\Grcmanager\Services\Review\ManagementReviewDossierRules;

$reviewId = (int) ($_POST['id'] ?? 0);
$review = new ManagementReview();

if (!isset($_POST['generate'])) {
    Session::checkRight(ManagementReview::$rightname, READ);
    // Issue #1114 : sans action (ouverture directe), pas d'écran : requête invalide.
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

Session::checkRight(ManagementReview::$rightname, UPDATE);
$review->check($reviewId, UPDATE);
$period = ManagementReviewDossierRules::normalizePeriod($_POST['period_start'] ?? '', $_POST['period_end'] ?? '');
if ($period === null) {
    Session::addMessageAfterRedirect(
        __('Choose a valid period: a start date before the end date, ten years at most.', 'grcmanager'),
        false,
        ERROR
    );
} elseif (ManagementReviewDossier::generate($review, $period['start'], $period['end']) > 0) {
    Session::addMessageAfterRedirect(
        __('Preparatory file generated and attached to the review.', 'grcmanager'),
        false,
        INFO
    );
} else {
    Session::addMessageAfterRedirect(__('The preparatory file could not be generated.', 'grcmanager'), false, ERROR);
}

Html::redirect(
    ManagementReview::getFormURLWithID($reviewId) . '&forcetab=' . urlencode(ManagementReviewDossier::class . '$1')
);

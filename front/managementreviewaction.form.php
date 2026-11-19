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

// Issue #542 : actions décidées en revue de direction. Ni menu ni écran de recherche propres : ce
// contrôleur ajoute, met à jour, supprime une action, ou crée les actions à partir des décisions de
// la revue, puis revient sur l'onglet « Dossier préparatoire » de la revue (même modèle que
// front/risktreatmentaction.form.php). Il faut pouvoir modifier la revue.
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\ManagementReviewAction;
use GlpiPlugin\Grcmanager\ManagementReviewDossier;

$reviewId = (int) ($_POST['plugin_grcmanager_managementreviews_id'] ?? 0);
$review = new ManagementReview();
$item = new ManagementReviewAction();

if (isset($_POST['add'])) {
    Session::checkRight(ManagementReviewAction::$rightname, CREATE);
    $review->check($reviewId, UPDATE);
    $item->add([
        'plugin_grcmanager_managementreviews_id' => $reviewId,
        'description'                            => (string) ($_POST['description'] ?? ''),
        'users_id'                               => (int) ($_POST['users_id'] ?? 0),
        'due_date'                               => (string) ($_POST['due_date'] ?? ''),
    ]);
} elseif (isset($_POST['from_decisions'])) {
    Session::checkRight(ManagementReviewAction::$rightname, CREATE);
    $review->check($reviewId, UPDATE);
    $created = ManagementReviewAction::createFromDecisions($review);
    Session::addMessageAfterRedirect(
        $created > 0
            ? sprintf(_n('%d action created from the decisions.', '%d actions created from the decisions.', $created, 'grcmanager'), $created)
            : __('Every decision already has its action.', 'grcmanager'),
        false,
        $created > 0 ? INFO : WARNING
    );
} elseif (isset($_POST['update'])) {
    Session::checkRight(ManagementReviewAction::$rightname, UPDATE);
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $reviewId = (int) $item->fields['plugin_grcmanager_managementreviews_id'];
    $review->check($reviewId, UPDATE);
    $item->update([
        'id'       => $item->getID(),
        'status'   => (string) ($_POST['status'] ?? ''),
        'users_id' => (int) ($_POST['users_id'] ?? $item->fields['users_id']),
        'due_date' => (string) ($_POST['due_date'] ?? ($item->fields['due_date'] ?? '')),
    ]);
} elseif (isset($_POST['purge'])) {
    Session::checkRight(ManagementReviewAction::$rightname, PURGE);
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $reviewId = (int) $item->fields['plugin_grcmanager_managementreviews_id'];
    $review->check($reviewId, UPDATE);
    $item->delete(['id' => $item->getID()], true);
} else {
    Session::checkRight(ManagementReviewAction::$rightname, READ);
}

Html::redirect(ManagementReview::getFormURLWithID($reviewId) . '&forcetab=' . urlencode(ManagementReviewDossier::class . '$1'));

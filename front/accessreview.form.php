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

include('../../../inc/includes.php');

$item = new PluginGrcmanagerAccessReview();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = (int) $item->add($_POST);
    Html::redirect($newID > 0
        ? PluginGrcmanagerAccessReview::getFormURLWithID($newID)
        : PluginGrcmanagerAccessReview::getSearchURL());
} elseif (isset($_POST['delete'])) {
    $item->check((int) $_POST['id'], DELETE);
    $item->delete($_POST);
    Html::redirect(PluginGrcmanagerAccessReview::getSearchURL());
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(PluginGrcmanagerAccessReview::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['launch'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $count = $item->launch();
    if ($count > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Campaign launched: %d account(s) to review, reviewers have been notified.', 'grcmanager'),
            $count
        ));
    }
    Html::back();
} elseif (isset($_POST['close'])) {
    $item->check((int) $_POST['id'], UPDATE);
    if ($item->close()) {
        Session::addMessageAfterRedirect(
            __('Campaign closed, PDF report archived in the documents.', 'grcmanager')
        );
    }
    Html::back();
} elseif (isset($_POST['prepare_next'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $nextID = $item->prepareNext();
    Html::redirect($nextID > 0
        ? PluginGrcmanagerAccessReview::getFormURLWithID($nextID)
        : PluginGrcmanagerAccessReview::getSearchURL());
} else {
    // displayFullPageForItem() (comme front/policy.form.php) affiche la barre d'onglets ; les
    // droits (READ, ou CREATE pour un nouvel élément) sont vérifiés par $item->can().
    PluginGrcmanagerAccessReview::displayFullPageForItem(
        $_GET['id'] ?? 0,
        ['central' => ['grcmanager', PluginGrcmanagerAccessReview::class], 'helpdesk' => []]
    );
}

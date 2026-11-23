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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\MaturityAssessment;
use GlpiPlugin\Grcmanager\MaturityCampaign;

// Issue #603 : campagne de maturité par mesure et sa grille. Chaque action vérifie le droit du plugin
// et l'entité de la campagne (check() du cœur) ; la grille exige de pouvoir modifier la campagne. Le
// jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new MaturityCampaign();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(MaturityCampaign::getFormURLWithID((int) $newID)
            . '&forcetab=' . rawurlencode(MaturityCampaign::class . '$1'));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) ($_POST['id'] ?? 0), PURGE);
    $item->delete($_POST, true);
    Html::redirect(MaturityCampaign::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) ($_POST['id'] ?? 0), UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['save_levels'])) {
    $campaignId = (int) ($_POST[MaturityCampaign::FK] ?? 0);
    if ($campaignId <= 0 || !$item->getFromDB($campaignId)) {
        throw new BadRequestHttpException();
    }
    $item->check($campaignId, UPDATE);
    $changed = MaturityAssessment::updateGrid($item, is_array($_POST['levels'] ?? null) ? $_POST['levels'] : []);
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        _n('%d control updated.', '%d controls updated.', $changed, 'grcmanager'),
        $changed
    )));
    Html::back();
} else {
    Session::checkRight(MaturityCampaign::$rightname, READ);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Campagne d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
        if (isset($_GET['compare_with'])) {
            // Campagne à comparer, retenue pour la session (même entité seulement).
            MaturityCampaign::chooseComparison($item, (int) $_GET['compare_with']);
        }
    } elseif (!MaturityCampaign::canCreate()) {
        throw new AccessDeniedHttpException();
    }

    \GlpiPlugin\Grcmanager\Menu::header(MaturityCampaign::class, MaturityCampaign::getTypeName(1));

    $item->display(['id' => $id]);

    Html::footer();
}

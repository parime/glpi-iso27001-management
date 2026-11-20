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

use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\PhishingResult;

// Issue #604 : campagne de simulation d'hameçonnage. Chaque action vérifie le droit du plugin et
// l'entité de la fiche (check() du cœur) ; supprimer les résultats individuels exige aussi le droit
// des registres sensibles. Le jeton CSRF est contrôlé par GLPI sur tout POST.
$item = new PhishingCampaign();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    if ($newID) {
        Html::redirect(PhishingCampaign::getFormURLWithID((int) $newID));
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(PhishingCampaign::getSearchURL());
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['anonymize'])) {
    $item->check((int) $_POST['id'], UPDATE);
    if (!PhishingResult::canImport($item)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    PhishingResult::anonymize($item);
    Session::addMessageAfterRedirect(htmlescape(__(
        'The individual results have been deleted. The rates of the campaign are kept.',
        'grcmanager'
    )));
    Html::redirect(PhishingCampaign::getFormURLWithID($item->getID()));
} else {
    if (!PhishingCampaign::canView()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        // Fiche d'une entité non accessible : refusée comme le reste du cœur.
        $item->check($id, READ);
    }

    Html::header(
        PhishingCampaign::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'grcmanager',
        \GlpiPlugin\Grcmanager\AccessReview::class,
        PhishingCampaign::class
    );

    $item->display(['id' => $id]);

    Html::footer();
}

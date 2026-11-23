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

use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;

// Issue #1114 : point d'envoi des formulaires de l'onglet (POST), sans écran propre ; ouvert
// directement, il répond « requête invalide » au lieu de renvoyer vers l'accueil.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

// Issue #879 : relier des politiques (entières ou par section) à des mesures de la SoA, ou retirer
// un lien, depuis l'onglet d'une politique ou d'une mesure. Droit de modifier les politiques ;
// chaque politique et chaque mesure est vérifiée (droit, entité) dans PolicyCoverage.
Session::checkRight(Policy::RIGHTNAME, UPDATE);
if (!PolicyCoverage::canEditLinks()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['add_links'])) {
    $policyIds = array_map('intval', is_array($_POST['policies_id'] ?? null) ? $_POST['policies_id'] : []);
    $resolved = PolicyCoverage::resolveControls($_POST['controls_id'] ?? [], (string) ($_POST['codes'] ?? ''));
    $added = PolicyCoverage::addLinks($policyIds, $resolved['ids'], (string) ($_POST['section'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        _n('%d link added.', '%d links added.', $added, 'grcmanager'),
        $added
    )), false, $added > 0 ? INFO : WARNING);
    if ($resolved['unknown'] !== []) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Codes not found in the Statement of Applicability: %s', 'grcmanager'),
            implode(', ', $resolved['unknown'])
        )), false, WARNING);
    }
} elseif (isset($_POST['remove_link'])) {
    if (!PolicyCoverage::removeLink((int) ($_POST['links_id'] ?? 0))) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
}

Html::back();

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

// Issue #1182 : enregistre une revue datée d'un risque (instantané de la cotation, constat, prochaine
// date de revue), puis revient sur la fiche du risque. Ni menu ni écran propres : l'onglet « Revues »
// du risque liste les revues et porte ce formulaire.
//
// Droit de modifier le registre des risques ET le risque lui-même (entité comprise) : un risque d'une
// autre entité reste fermé, même en forçant le formulaire. L'instantané est pris côté serveur
// (RiskReview::prepareInputForAdd()), jamais dans la requête. Jeton CSRF vérifié par GLPI pour tout
// envoi POST.
if (isset($_POST['add'])) {
    $riskId = (int) ($_POST['plugin_grcmanager_risks_id'] ?? 0);
    Session::checkRight(\GlpiPlugin\Grcmanager\RiskReview::RIGHTNAME, UPDATE);
    $risk = new \GlpiPlugin\Grcmanager\Risk();
    $risk->check($riskId, UPDATE);
    $review = new \GlpiPlugin\Grcmanager\RiskReview();
    $added = $review->add([
        'plugin_grcmanager_risks_id' => $riskId,
        'comment'                    => (string) ($_POST['comment'] ?? ''),
        'next_review_date'           => (string) ($_POST['next_review_date'] ?? ''),
    ]);
    if ($added) {
        Session::addMessageAfterRedirect(htmlescape(__('Review recorded.', 'grcmanager')));
    }
    Html::redirect(\GlpiPlugin\Grcmanager\Risk::getFormURLWithID($riskId));
}

// Consultation directe : la liste des risques, pour qui peut la lire.
Session::checkRight(\GlpiPlugin\Grcmanager\RiskReview::RIGHTNAME, READ);
Html::redirect(\GlpiPlugin\Grcmanager\Risk::getSearchURL());

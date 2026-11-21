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

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AccessReviewBmcAccount;

// Issue #843, lot 2 : comptes des contrôleurs de gestion des serveurs dans une campagne de revue
// des accès. Voir : revue des accès et lecture des ordinateurs ; agir : mise à jour de CETTE
// campagne (vérifiée sur la campagne, donc sur son entité). Jeton CSRF vérifié par GLPI.
if (!AccessReviewBmcAccount::canView()) {
    throw new GlpixceptionHttpAccessDeniedHttpException();
}

if (isset($_POST['snapshot'])) {
    $campaign = new AccessReview();
    $campaign->check((int) ($_POST['campaign_id'] ?? 0), UPDATE);
    $count = AccessReviewBmcAccount::snapshot($campaign);
    Session::addMessageAfterRedirect(htmlescape(
        sprintf(__('%d account(s) of the controllers added to the campaign.', 'grcmanager'), $count)
    ), false, $count > 0 ? INFO : WARNING);
    Html::back();
}

if (isset($_POST['decide'])) {
    $lineId = (int) ($_POST['line_id'] ?? 0);
    (new AccessReview())->check(AccessReviewBmcAccount::campaignOf($lineId), UPDATE);
    $error = AccessReviewBmcAccount::decide(
        $lineId,
        (string) $_POST['decide'],
        (string) ($_POST['comment'] ?? ''),
        (int) Session::getLoginUserID()
    );
    if ($error !== '') {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
}

Html::redirect(AccessReview::getSearchURL());

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
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;

include('../../../inc/includes.php');

// Issue #126 : un responsable n'a souvent aucun droit sur le plugin. Cette page n'exige qu'une
// session : elle ne montre que les lignes confiées à l'utilisateur connecté, et decide() vérifie
// pour chaque ligne qu'il en est le responsable (ou qu'il gère les campagnes).
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

if (isset($_POST['save_decisions'])) {
    global $DB;

    $decisions = is_array($_POST['decisions'] ?? null) ? $_POST['decisions'] : [];
    $comments = is_array($_POST['comments'] ?? null) ? $_POST['comments'] : [];
    $saved = 0;
    foreach ($decisions as $lineId => $decision) {
        $comment = trim((string) ($comments[$lineId] ?? ''));
        $current = $DB->request([
            'FROM'  => PluginGrcmanagerAccessReview::LINES_TABLE,
            'WHERE' => ['id' => (int) $lineId],
        ])->current();
        // Ligne inchangée ignorée seulement si elle est confiée à cet utilisateur : sinon, l'absence
        // d'erreur révélerait la décision d'une ligne d'une autre campagne. decide() contrôle les
        // autres cas (gestionnaire de la campagne, refus).
        if (
            $current !== null
            && (int) $current['reviewers_id'] === $usersId
            && $current['decision'] === $decision
            && (string) $current['comment'] === $comment
        ) {
            continue;
        }
        $error = PluginGrcmanagerAccessReview::decide((int) $lineId, (string) $decision, $comment, $usersId);
        if ($error === '') {
            $saved++;
        } else {
            // Nom du compte seulement pour une ligne confiée à cet utilisateur : sinon l'erreur
            // révélerait l'identité d'un compte d'une campagne à laquelle il n'a pas accès.
            $name = $current !== null && (int) $current['reviewers_id'] === $usersId
                ? getUserName((int) $current['users_id'])
                : '#' . (int) $lineId;
            Session::addMessageAfterRedirect(htmlescape($name . ' : ' . $error), false, ERROR);
        }
    }
    if ($saved > 0) {
        Session::addMessageAfterRedirect(sprintf(__('%d décision(s) enregistrée(s).', 'grcmanager'), $saved));
    }
    Html::back();
}

Html::header(
    __('Mes revues d\'accès', 'grcmanager'),
    PluginGrcmanagerAccessReview::getMineURL(),
    'grcmanager',
    PluginGrcmanagerAccessReview::class
);

$campaigns = [];
foreach (PluginGrcmanagerAccessReview::getLinesForReviewer($usersId) as $line) {
    $campaignId = (int) $line['plugin_grcmanager_accessreviews_id'];
    $campaigns[$campaignId] ??= [
        'name'     => (string) $line['campaign_name'],
        'due_date' => (string) $line['campaign_due_date'],
        'late'     => date('Y-m-d') > (string) $line['campaign_due_date'],
        'lines'    => [],
    ];
    $campaigns[$campaignId]['lines'][] = $line;
}
foreach ($campaigns as &$campaign) {
    $campaign['progress'] = AccessReviewLogic::progress(array_column($campaign['lines'], 'decision'));
}
unset($campaign);

TemplateRenderer::getInstance()->display('@grcmanager/access_review_mine.html.twig', [
    'campaigns' => array_values($campaigns),
    'decisions' => PluginGrcmanagerAccessReview::getDecisionLabels(),
    'sources'   => PluginGrcmanagerAccessReview::getSourceLabels(),
    'action'    => PluginGrcmanagerAccessReview::getMineURL(),
]);

Html::footer();

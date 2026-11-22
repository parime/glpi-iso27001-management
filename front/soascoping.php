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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\SoaScoping;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules;

// Issue #873 : cadrage guidé de la SoA de l'entité active. Lecture avec le droit de lire la SoA ;
// réponses et décisions avec le droit de la modifier (jeton CSRF vérifié par GLPI). Rien n'est
// appliqué sans une décision explicite de la personne.
Session::checkRight(SoaScoping::RIGHTNAME, READ);
if (!SoaScoping::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$entityId = SoaScoping::entity();

// Une décision : par lot (controls[] et decide) ou sur une seule mesure (accept_one, reject_one).
if (isset($_POST['accept_one'])) {
    $_POST['decide'] = SoaScopingRules::DECISION_ACCEPTED;
    $_POST['controls'] = [(int) $_POST['accept_one']];
} elseif (isset($_POST['reject_one'])) {
    $_POST['decide'] = SoaScopingRules::DECISION_REJECTED;
    $_POST['controls'] = [(int) $_POST['reject_one']];
}
if (isset($_POST['save_answers']) || isset($_POST['decide'])) {
    if (!SoaScoping::canDecide()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if (isset($_POST['save_answers'])) {
        SoaScoping::saveAnswers($entityId, (array) ($_POST['answers'] ?? []));
        Session::addMessageAfterRedirect(htmlescape(__(
            'Answers saved. Review the proposals below: nothing is applied to the Statement of '
                . 'Applicability until you accept it.',
            'grcmanager'
        )));
    } else {
        $decision = (string) $_POST['decide'];
        $ids = array_map('intval', (array) ($_POST['controls'] ?? []));
        $done = SoaScoping::decide($entityId, $ids, $decision);
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            $decision === SoaScopingRules::DECISION_ACCEPTED
                ? __('Proposals accepted: %d.', 'grcmanager')
                : __('Proposals rejected: %d.', 'grcmanager'),
            $done
        )));
    }
    Html::redirect(SoaScoping::getSearchURL());
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cadrage-soa-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
    foreach (SoaScoping::csvRows($entityId) as $line) {
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

// Aperçu d'un lot : les mesures cochées, avant toute modification.
$previewIds = [];
if (SoaScoping::canDecide() && isset($_POST['preview_pending'])) {
    foreach (SoaScoping::rows($entityId) as $row) {
        if ($row['status'] === SoaScopingRules::STATUS_DIFFERS) {
            $previewIds[] = $row['id'];
        }
    }
} elseif (SoaScoping::canDecide() && isset($_POST['preview'])) {
    $previewIds = array_map('intval', (array) ($_POST['controls'] ?? []));
}

Html::header(SoaScoping::getTypeName(), SoaScoping::getSearchURL(), 'grcmanager', Control::class, SoaScoping::class);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, SoaScoping::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('soascoping');
SoaScoping::showPage($entityId, $previewIds);
Html::footer();

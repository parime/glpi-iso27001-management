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

use GlpiPlugin\Grcmanager\ReportedRisk;
use GlpiPlugin\Grcmanager\Risk;

/**
 * Issue #872 : file de tri des risques signalés. Lecture avec le droit de lire le registre des
 * risques, dans les entités de la session ; décisions avec le droit de créer et modifier les risques
 * (jeton CSRF vérifié par GLPI). Chaque décision est prise par une personne.
 */
Session::checkRight(ReportedRisk::RIGHTNAME, READ);

if (isset($_POST['decide'])) {
    if (!ReportedRisk::canTriage()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $result = ReportedRisk::decide(
        (int) ($_POST['id'] ?? 0),
        (string) $_POST['decide'],
        (string) ($_POST['decision_comment'] ?? ''),
        (int) ($_POST['plugin_grcmanager_risks_id'] ?? 0),
        (string) ($_POST['category'] ?? '')
    );
    if ($result['error'] === 'denied') {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if ($result['error'] !== null) {
        Session::addMessageAfterRedirect(htmlescape(ReportedRisk::errorMessage($result['error'])), false, ERROR);
        Html::back();
    }
    if ((string) $_POST['decide'] === 'accepted') {
        // Le risque créé est à compléter (propriétaire, probabilité, impact) : on l'ouvre.
        Session::addMessageAfterRedirect(htmlescape(__(
            'Risk created from the report. Complete its owner, likelihood and impact.',
            'grcmanager'
        )));
        Html::redirect(Risk::getFormURLWithID($result['risks_id']));
    }
    Session::addMessageAfterRedirect(htmlescape(__('Decision recorded. The author has been informed.', 'grcmanager')));
    Html::redirect(ReportedRisk::getSearchURL());
}

Html::header(
    ReportedRisk::getTypeName(Session::getPluralNumber()),
    ReportedRisk::getSearchURL(),
    'grcmanager',
    Risk::class,
    ReportedRisk::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Risk::class, ReportedRisk::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('reportedrisk');
$filter = (string) ($_GET['status'] ?? '');
ReportedRisk::showQueue(in_array($filter, ['all', 'new', 'accepted', 'merged', 'rejected'], true) ? $filter : '');
Html::footer();

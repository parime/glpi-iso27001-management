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

// Issue #142 : calendrier de conformité (écran et export iCal).
Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceEvent::$rightname, READ);

$mine = !empty($_GET['mine']);

if (!empty($_GET['ics'])) {
    $ics = \GlpiPlugin\Grcmanager\ComplianceEvent::exportIcs($mine);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="calendrier-conformite.ics"');
    echo $ics;
    return;
}

Html::header(
    __('Compliance calendar', 'grcmanager'),
    \GlpiPlugin\Grcmanager\ComplianceEvent::getCalendarURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Dashboard::class,
    \GlpiPlugin\Grcmanager\ComplianceEvent::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Dashboard::class,
    \GlpiPlugin\Grcmanager\ComplianceEvent::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('compliancecalendar');

\GlpiPlugin\Grcmanager\ComplianceEvent::showCalendar($mine);

Html::footer();

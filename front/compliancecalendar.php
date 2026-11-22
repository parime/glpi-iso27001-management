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

// Issue #142 : calendrier de conformité (écran et export iCal).
Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceEvent::$rightname, READ);

// Issue #664 : lien d'abonnement du calendrier, porté par un jeton dédié que l'utilisateur crée,
// régénère ou révoque lui-même (jeton CSRF vérifié par GLPI sur ce POST).
if (isset($_POST['generate_calendar_token']) || isset($_POST['revoke_calendar_token'])) {
    $usersId = (int) Session::getLoginUserID();
    if (isset($_POST['generate_calendar_token'])) {
        \GlpiPlugin\Grcmanager\CalendarToken::rememberForDisplay(
            \GlpiPlugin\Grcmanager\CalendarToken::generate($usersId)
        );
        Session::addMessageAfterRedirect(htmlescape(__(
            'New subscription link created. The previous link no longer works.',
            'grcmanager'
        )));
    } elseif (\GlpiPlugin\Grcmanager\CalendarToken::revoke($usersId)) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Subscription link revoked: calendars using it no longer receive your deadlines.',
            'grcmanager'
        )));
    }
    Html::redirect(
        \GlpiPlugin\Grcmanager\ComplianceEvent::getCalendarURL() . (!empty($_POST['mine']) ? '?mine=1' : '')
        . '#grcmanager-ical-subscription'
    );
}

$mine = !empty($_GET['mine']);

if (!empty($_GET['ics'])) {
    $ics = \GlpiPlugin\Grcmanager\ComplianceEvent::exportIcs($mine);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="calendrier-conformite.ics"');
    echo $ics;
    return;
}

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\ComplianceEvent::class,
    __('Compliance calendar', 'grcmanager'),
    \GlpiPlugin\Grcmanager\ComplianceEvent::getCalendarURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\ComplianceEvent::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('compliancecalendar');

\GlpiPlugin\Grcmanager\ComplianceEvent::showCalendar($mine);

Html::footer();

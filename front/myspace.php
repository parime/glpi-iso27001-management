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

use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\PersonalDigest;

// Issue #539 : « Mon espace GRC ». Comme « Mes revues d'accès », une session suffit : la page ne
// montre que ce qui est confié à l'utilisateur connecté, et chaque source n'est lue que si ses
// droits le permettent (MySpaceProvider). Lecture seule.
Session::checkLoginUser();

// Issue #877 : choix du récapitulatif par e-mail (chacun pour soi), et envoi immédiat à soi-même.
$usersId = (int) Session::getLoginUserID();
if (isset($_POST['save_personal_digest'])) {
    if (PersonalDigest::saveFrequency($usersId, $_POST['personal_digest_frequency'] ?? '')) {
        Session::addMessageAfterRedirect(__('Your choice has been saved.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(
            __('Choose how often you want to receive your summary.', 'grcmanager'),
            false,
            ERROR
        );
    }
    Html::back();
}
if (isset($_POST['send_personal_digest'])) {
    $result = PersonalDigest::canBeSent()
        ? PersonalDigest::send($usersId, date('Y-m-d'))
        : PersonalDigest::NOT_SENT;
    if ($result === PersonalDigest::SENT) {
        Session::addMessageAfterRedirect(__('Your summary has been sent to your email address.', 'grcmanager'));
    } elseif ($result === PersonalDigest::NOTHING_WAITING) {
        Session::addMessageAfterRedirect(
            __('Nothing is waiting for you: no email has been sent.', 'grcmanager'),
            false,
            WARNING
        );
    } else {
        Session::addMessageAfterRedirect(
            __(
                'Your summary could not be sent: check that you have an email address, or ask your GLPI '
                    . 'administrator.',
                'grcmanager'
            ),
            false,
            ERROR
        );
    }
    Html::back();
}

\GlpiPlugin\Grcmanager\Menu::header(MySpace::class, MySpace::getTypeName(), MySpace::getSearchURL());
\GlpiPlugin\Grcmanager\PageHelp::show('myspace');
MySpace::show();
PersonalDigest::showPreference($usersId);
Html::footer();

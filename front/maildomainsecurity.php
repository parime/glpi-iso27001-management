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

use GlpiPlugin\Grcmanager\MailDomainSecurity;

// Issue #855 : sécurité de la messagerie et des domaines (A.5.14, A.8.21). Droit du plugin, domaines
// des entités de la session. Lecture DNS publique sur clic seulement : rien n'est envoyé ni modifié.
Session::checkRight(MailDomainSecurity::RIGHTNAME, READ);

$back = MailDomainSecurity::getSearchURL() . '#grc-mail-domains';

if (isset($_POST['add_domain'])) {
    Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
    $added = MailDomainSecurity::add($_POST);
    if ($added['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(MailDomainSecurity::errorMessage($added['error'])), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(
            __('Domain added. Click "Check now" to read its DNS records.', 'grcmanager')
        ));
    }
    Html::redirect($back);
}

if (isset($_POST['update_domain'])) {
    Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
    $error = MailDomainSecurity::update((int) ($_POST['id'] ?? 0), $_POST);
    if ($error !== '') {
        Session::addMessageAfterRedirect(htmlescape(MailDomainSecurity::errorMessage($error)), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('Domain saved.', 'grcmanager')));
    }
    Html::redirect($back);
}

if (isset($_POST['delete_domain'])) {
    Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
    if (MailDomainSecurity::delete((int) ($_POST['id'] ?? 0))) {
        Session::addMessageAfterRedirect(htmlescape(__('Domain removed from the list.', 'grcmanager')));
    } else {
        Session::addMessageAfterRedirect(htmlescape(MailDomainSecurity::errorMessage('not_found')), false, ERROR);
    }
    Html::redirect($back);
}

if (isset($_POST['check_domain'])) {
    Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
    if (MailDomainSecurity::check((int) ($_POST['id'] ?? 0))) {
        Session::addMessageAfterRedirect(htmlescape(__('DNS records read.', 'grcmanager')));
    } else {
        Session::addMessageAfterRedirect(htmlescape(MailDomainSecurity::errorMessage('not_found')), false, ERROR);
    }
    Html::redirect($back);
}

if (isset($_POST['check_all_domains'])) {
    Session::checkRight(MailDomainSecurity::RIGHTNAME, UPDATE);
    $count = MailDomainSecurity::checkAll();
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        _n('%d domain checked.', '%d domains checked.', $count, 'grcmanager'),
        $count
    )), false, $count > 0 ? INFO : WARNING);
    Html::redirect($back);
}

// Issue #1042 : le serveur DNS de la question DNSSEC se règle dans la configuration (onglet
// Connecteurs). L'ancien envoi de cette page reste accepté pendant une version et y mène.
\GlpiPlugin\Grcmanager\Config\Block\MailDnsConnectorBlock::handleLegacyPost();

\GlpiPlugin\Grcmanager\Menu::header(
    MailDomainSecurity::class,
    MailDomainSecurity::getTypeName(),
    MailDomainSecurity::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(MailDomainSecurity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('maildomainsecurity');

MailDomainSecurity::showPage(isset($_GET['edit']) ? (int) $_GET['edit'] : 0);

Html::footer();

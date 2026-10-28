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

use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;

include('../../../inc/includes.php');

// Issue #184 : hygiène des comptes (droit du plugin + lecture native des utilisateurs).
Session::checkRight(PluginGrcmanagerAccountHygiene::RIGHTNAME, READ);
Session::checkRight(User::$rightname, READ);

$days = AccountHygieneRules::normalizeDays($_REQUEST['days'] ?? AccountHygieneRules::DEFAULT_INACTIVITY_DAYS);

if (isset($_POST['create_ticket'])) {
    Session::checkRight(Ticket::$rightname, CREATE);
    $usersIds = array_values(array_map('intval', (array) ($_POST['users_id'] ?? [])));
    $ticketsId = PluginGrcmanagerAccountHygiene::createReviewTicket($usersIds, $days);
    if ($ticketsId > 0) {
        $message = sprintf(__('Review ticket #%d created.', 'grcmanager'), $ticketsId);
        Session::addMessageAfterRedirect(htmlescape($message));
        Html::redirect(Ticket::getFormURLWithID($ticketsId));
    }
    $message = __('Tick at least one account to check.', 'grcmanager');
    Session::addMessageAfterRedirect(htmlescape($message), false, WARNING);
    Html::redirect(PluginGrcmanagerAccountHygiene::getSearchURL() . '?days=' . $days);
}

Html::header(
    PluginGrcmanagerAccountHygiene::getTypeName(),
    PluginGrcmanagerAccountHygiene::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerAccessReview::class,
    PluginGrcmanagerAccountHygiene::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerAccessReview::class, PluginGrcmanagerAccountHygiene::class);
PluginGrcmanagerPageHelp::show('accounthygiene');

PluginGrcmanagerAccountHygiene::showList($days);

Html::footer();

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

use GlpiPlugin\Grcmanager\Delegation;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig;

// Issue #618 : décisions prises par un suppléant pendant l'absence du titulaire. Journal en lecture
// seule pour les lecteurs du plugin (entités de la session) ; le choix des types délégables est un
// réglage global : droit de modifier le plugin ET la configuration de GLPI.
if (isset($_POST['update_delegation_config'])) {
    Session::checkRight(Installer::RIGHT_NAME, UPDATE);
    Session::checkRight('config', UPDATE);
    DelegationConfig::save((array) ($_POST['delegation_types'] ?? []));
    Session::addMessageAfterRedirect(htmlescape(__('Settings saved.', 'grcmanager')));
    Html::redirect(Delegation::configURL());
}

Session::checkRight(Installer::RIGHT_NAME, READ);

Html::header(Delegation::getTypeName(2), Delegation::getSearchURL(), 'tools', \GlpiPlugin\Grcmanager\Risk::class);
\GlpiPlugin\Grcmanager\PageHelp::show('delegation');
Delegation::showJournal();
Html::footer();

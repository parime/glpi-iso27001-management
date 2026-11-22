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

// Issue #618 : décisions prises par un suppléant pendant l'absence du titulaire. Journal en lecture
// seule pour les lecteurs du plugin (entités de la session). Issue #1047 : le choix des types
// délégables est enregistré par la configuration (DelegationBlock) ; l'ancien envoi vers cette page
// reste accepté pendant une version.
if (isset($_POST['update_delegation_config'])) {
    \GlpiPlugin\Grcmanager\Config\MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\PeopleTab::label());
    \GlpiPlugin\Grcmanager\Config\Block\DelegationBlock::handle();
}

Session::checkRight(Installer::RIGHT_NAME, READ);

// Issue #1038 : sous-page de la rubrique Organisation du SMSI, plus dans le secteur « Outils ».
\GlpiPlugin\Grcmanager\Menu::header(Delegation::class, Delegation::getTypeName(2));
\GlpiPlugin\Grcmanager\Menu::showSection(Delegation::class);
\GlpiPlugin\Grcmanager\PageHelp::show('delegation');
Delegation::showJournal();
Html::footer();

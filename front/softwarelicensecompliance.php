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

use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;

// Issue #779 : conformité des licences logicielles (A.5.32). Droit des registres sensibles du plugin
// et lecture native des logiciels et des licences ; constats des entités de la session seulement.
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);
Session::checkRight(SoftwareLicense::$rightname, READ);

// Issue #1044 : ce réglage est dans l'onglet Parc de la configuration du plugin. L'ancien envoi
// reste accepté pendant une version : enregistré, puis renvoi sur l'onglet avec un message.
if (isset($_POST['save_license_watch']) || isset($_POST['reset_license_watch'])) {
    \GlpiPlugin\Grcmanager\Config\Block\LicenseWatchBlock::handle(true);
}

\GlpiPlugin\Grcmanager\Menu::header(
    SoftwareLicenseCompliance::class,
    SoftwareLicenseCompliance::getTypeName(),
    SoftwareLicenseCompliance::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(SoftwareLicenseCompliance::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('softwarelicensecompliance');

SoftwareLicenseCompliance::showPage();

Html::footer();

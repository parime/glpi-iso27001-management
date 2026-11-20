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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\SecureDevelopment;

// Issue #777 : registre des applications, mises en production et indicateurs du développement
// sécurisé (A.8.25 à A.8.31). Droit général du plugin ; applications et changements avec leurs
// droits natifs et les entités de la session (SecureDevelopment::applications()/releases()).
Session::checkRight(Installer::RIGHT_NAME, READ);

Html::header(
    SecureDevelopment::getTypeName(Session::getPluralNumber()),
    SecureDevelopment::getPageURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    SecureDevelopment::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, SecureDevelopment::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('securedevelopment');
SecureDevelopment::showPage();

Html::footer();

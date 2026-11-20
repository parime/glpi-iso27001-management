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

use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\PhysicalSecurityAssessment;

// Issue #778 : sécurité physique des sites, comparaison de la dernière évaluation de chaque site.
// Droit général du plugin ; seules les évaluations des entités de la session sont lues.
Session::checkRight(PhysicalSecurityAssessment::$rightname, READ);

Html::header(
    PhysicalSecurity::getTypeName(),
    PhysicalSecurity::getPageURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    PhysicalSecurity::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, PhysicalSecurity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('physicalsecurity');
PhysicalSecurity::showPage();

Html::footer();

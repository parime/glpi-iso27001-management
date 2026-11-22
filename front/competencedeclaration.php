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

use GlpiPlugin\Grcmanager\CompetenceDeclaration;

// Issue #774 : liste de la rubrique des compétences, avec les droits et les entités du cœur.
// Données de personnes : droit du plugin et lecture des utilisateurs (CompetenceDeclaration::canView()).
Session::checkRight(CompetenceDeclaration::$rightname, READ);
Session::checkRight(User::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\IsmsFunction::class,
    CompetenceDeclaration::getTypeName(Session::getPluralNumber())
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\IsmsFunction::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('competencedeclaration');

Search::show(CompetenceDeclaration::class);

Html::footer();

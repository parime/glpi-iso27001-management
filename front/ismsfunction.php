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

use GlpiPlugin\Grcmanager\CompetenceExport;
use GlpiPlugin\Grcmanager\CompetenceMatrix;
use GlpiPlugin\Grcmanager\IsmsFunction;

// Issue #774 : compétences (article 7.2). Droit général du plugin ; seules les fonctions des entités
// de la session sont lues, et les personnes exigent aussi la lecture des utilisateurs. Le jeton CSRF
// est contrôlé par GLPI sur tout POST.
Session::checkRight(IsmsFunction::$rightname, READ);

if (isset($_POST['add_defaults'])) {
    Session::checkRight(IsmsFunction::$rightname, CREATE);
    $added = IsmsFunction::addDefaults((int) Session::getActiveEntity());
    Session::addMessageAfterRedirect(sprintf(
        __('Default functions added: %1$d, default competences added: %2$d.', 'grcmanager'),
        $added['functions'],
        $added['competences']
    ));
    Html::redirect(IsmsFunction::getSearchURL());
}
if (isset($_POST['archive'])) {
    if (!CompetenceExport::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    if (CompetenceExport::archive() > 0) {
        Session::addMessageAfterRedirect(__(
            'The competence file has been archived as evidence of requirement 7.2.',
            'grcmanager'
        ));
    } else {
        Session::addMessageAfterRedirect(__('The competence file could not be archived.', 'grcmanager'), false, ERROR);
    }
    Html::redirect(IsmsFunction::getSearchURL());
}

\GlpiPlugin\Grcmanager\Menu::header(
    IsmsFunction::class,
    __('Competences', 'grcmanager'),
    IsmsFunction::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(IsmsFunction::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('ismsfunction');
CompetenceMatrix::showPage();

Html::footer();

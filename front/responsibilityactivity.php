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

use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\ResponsibilityExport;

// Issue #612 : matrice des responsabilités du SMSI. Droit général du plugin ; seules les activités
// des entités de la session sont lues. Le jeton CSRF est contrôlé par GLPI sur tout POST.
Session::checkRight(ResponsibilityActivity::$rightname, READ);

if (isset($_POST['add_defaults'])) {
    Session::checkRight(ResponsibilityActivity::$rightname, CREATE);
    $added = ResponsibilityActivity::addDefaults((int) Session::getActiveEntity());
    Session::addMessageAfterRedirect(sprintf(
        _n('%d default activity added.', '%d default activities added.', $added, 'grcmanager'),
        $added
    ));
    Html::redirect(ResponsibilityActivity::getSearchURL());
}
if (isset($_POST['archive'])) {
    Session::checkRight(ResponsibilityActivity::$rightname, UPDATE);
    if (ResponsibilityExport::archive() > 0) {
        Session::addMessageAfterRedirect(__(
            'The responsibility matrix has been archived as evidence of requirement 5.3 and control A.5.3.',
            'grcmanager'
        ));
    } else {
        Session::addMessageAfterRedirect(__('The matrix could not be archived.', 'grcmanager'), false, ERROR);
    }
    Html::redirect(ResponsibilityActivity::getSearchURL());
}

Html::header(
    __('Responsibility matrix', 'grcmanager'),
    ResponsibilityActivity::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    ResponsibilityActivity::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, ResponsibilityActivity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('responsibilityactivity');
ResponsibilityActivity::showMatrix();

Html::footer();

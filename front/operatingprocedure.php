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

use GlpiPlugin\Grcmanager\OperatingProcedure;
use GlpiPlugin\Grcmanager\Policy;

// Issue #775 : registre des procédures d'exploitation et couverture par domaine (A.5.37). Droits de
// la bibliothèque des politiques (droit général du plugin).
Session::checkRight(Policy::$rightname, READ);

if (isset($_POST['archive'])) {
    // Archiver crée un document lié à la mesure A.5.37 : droit de modifier les politiques.
    Session::checkRight(Policy::$rightname, UPDATE);
    $documentsId = OperatingProcedure::archive();
    Session::addMessageAfterRedirect(
        htmlescape($documentsId > 0
            ? __('The register has been archived as evidence on control A.5.37.', 'grcmanager')
            : __('The register could not be archived.', 'grcmanager')),
        false,
        $documentsId > 0 ? INFO : ERROR
    );
    Html::redirect(OperatingProcedure::getRegisterURL());
}

\GlpiPlugin\Grcmanager\Menu::header(
    OperatingProcedure::class,
    OperatingProcedure::getTypeName(Session::getPluralNumber()),
    OperatingProcedure::getRegisterURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(OperatingProcedure::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('operatingprocedure');
OperatingProcedure::showRegister();

Html::footer();

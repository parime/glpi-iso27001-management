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

use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\OfficialContact;

// Issue #771 : registre des contacts officiels (A.5.5, A.5.6). Droit du contexte de l'organisation
// (droit général du plugin) ; contacts des entités de la session seulement.
Session::checkRight(ContextItem::$rightname, READ);

if (isset($_POST['archive'])) {
    // Issue #771, lot 3 : archiver crée un document lié aux mesures A.5.5 et A.5.6 : droit de
    // modifier le contexte de l'organisation.
    Session::checkRight(ContextItem::$rightname, UPDATE);
    $documentsId = OfficialContact::archive();
    Session::addMessageAfterRedirect(
        htmlescape($documentsId > 0
            ? __('The register has been archived as evidence on controls A.5.5 and A.5.6.', 'grcmanager')
            : __('The register could not be archived.', 'grcmanager')),
        false,
        $documentsId > 0 ? INFO : ERROR
    );
    Html::redirect(OfficialContact::getRegisterURL());
}

\GlpiPlugin\Grcmanager\Menu::header(
    OfficialContact::class,
    OfficialContact::getTypeName(Session::getPluralNumber()),
    OfficialContact::getRegisterURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(OfficialContact::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('officialcontact');
OfficialContact::showRegister();

Html::footer();

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

Html::header(
    OfficialContact::getTypeName(Session::getPluralNumber()),
    OfficialContact::getRegisterURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    OfficialContact::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, OfficialContact::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('officialcontact');
OfficialContact::showRegister();

Html::footer();

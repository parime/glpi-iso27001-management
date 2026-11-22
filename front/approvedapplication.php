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

use GlpiPlugin\Grcmanager\ApprovedApplication;

// Issue #622 : registre des applications et services en ligne approuvés (A.5.23), indicateurs et
// rapprochement avec l'inventaire logiciel. Droit général du plugin ; applications et logiciels
// avec leurs droits natifs et les entités de la session. Lecture seule : rien n'est modifié ici.
Session::checkRight(ApprovedApplication::RIGHTNAME, READ);

Html::header(
    ApprovedApplication::getTypeName(Session::getPluralNumber()),
    ApprovedApplication::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    ApprovedApplication::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Risk::class, ApprovedApplication::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('approvedapplication');
ApprovedApplication::showPage(max(0, (int) ($_GET['softwarecategories_id'] ?? 0)));

Html::footer();

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

// Issue #546 : liste globale des fiches de conformité sécurité, filtrable (entité, groupe, état,
// composante en écart). Sous-page de la rubrique « Revue des accès ».
Session::checkRight(\GlpiPlugin\Grcmanager\PersonnelCompliance::RIGHTNAME, READ);
Session::checkRight(User::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\PersonnelCompliance::class,
    \GlpiPlugin\Grcmanager\PersonnelCompliance::getTypeName(),
    \GlpiPlugin\Grcmanager\PersonnelCompliance::getSearchURL()
);
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\PersonnelCompliance::class);
\GlpiPlugin\Grcmanager\PageHelp::show('personnelcompliance');

\GlpiPlugin\Grcmanager\PersonnelCompliance::showList(\GlpiPlugin\Grcmanager\PersonnelCompliance::filters($_GET));

Html::footer();

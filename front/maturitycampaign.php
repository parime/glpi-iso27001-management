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

use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\MaturityCampaign;

// Issue #603 : campagnes de maturité par mesure (niveau actuel, niveau cible et écarts), avec les
// droits et les entités du cœur.
Session::checkRight(MaturityCampaign::$rightname, READ);

Html::header(
    MaturityCampaign::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    Dashboard::class,
    MaturityCampaign::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(Dashboard::class, MaturityCampaign::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('maturitycampaign');

Search::show(MaturityCampaign::class);

Html::footer();

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

use GlpiPlugin\Grcmanager\CrisisScenario;

// Issue #776 : scénarios de crise sécurité de l'information du PCA et leur couverture (A.5.29).
// Droit de la continuité ; seuls les scénarios et processus des entités de la session sont lus. Le
// jeton CSRF est contrôlé par GLPI sur tout POST.
Session::checkRight(CrisisScenario::$rightname, READ);

if (isset($_POST['add_defaults'])) {
    Session::checkRight(CrisisScenario::$rightname, CREATE);
    $added = CrisisScenario::addDefaults((int) Session::getActiveEntity());
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        _n('%d default scenario added.', '%d default scenarios added.', $added, 'grcmanager'),
        $added
    )));
    Html::redirect(CrisisScenario::getSearchURL());
}

Html::header(
    CrisisScenario::getTypeName(Session::getPluralNumber()),
    CrisisScenario::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    CrisisScenario::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\AccessReview::class, CrisisScenario::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('crisisscenario');
CrisisScenario::showRegister();

Html::footer();

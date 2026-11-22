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

use GlpiPlugin\Grcmanager\PatchCompliance;

// Issue #854 : retard des correctifs de sécurité (A.8.8, A.8.19). Droit du plugin et lecture native
// des ordinateurs ; ordinateurs des entités de la session seulement. Lecture seule de l'inventaire.
Session::checkRight(PatchCompliance::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

// Issue #1044 : ce réglage est dans l'onglet Parc de la configuration du plugin. L'ancien envoi
// reste accepté pendant une version : enregistré, puis renvoi sur l'onglet avec un message.
if (isset($_POST['save_patch_targets'])) {
    \GlpiPlugin\Grcmanager\Config\Block\PatchTargetsBlock::handle(true);
}

Html::header(
    PatchCompliance::getTypeName(),
    PatchCompliance::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    PatchCompliance::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    PatchCompliance::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('patchcompliance');

PatchCompliance::showPage();

Html::footer();

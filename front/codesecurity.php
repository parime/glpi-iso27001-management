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

use GlpiPlugin\Grcmanager\CodeSecurity;

// Issue #858 : sécurité du code lue dans GitHub et GitLab (A.8.28, A.8.29). Droit du plugin et lecture
// native des applications ; applications des entités de la session seulement. Rien n'est jamais
// modifié chez GitHub ni GitLab.
Session::checkRight(CodeSecurity::RIGHTNAME, READ);
Session::checkRight(Appliance::$rightname, READ);

$back = static function (string $anchor): void {
    Html::redirect(CodeSecurity::getSearchURL() . '#' . $anchor);
};

// Issue #1043 : la connexion à GitHub et GitLab (jetons, serveurs, lecture automatique) se règle dans
// la configuration, onglet Connecteurs. L'ancien envoi de cette page reste accepté pendant une version
// et y mène.
\GlpiPlugin\Grcmanager\Config\Block\CodeHostingConnectorBlock::handleLegacyPost();

// Lecture sur clic, droit de modifier.
if (isset($_POST['read_codesecurity'])) {
    Session::checkRight(CodeSecurity::RIGHTNAME, UPDATE);
    $result = CodeSecurity::read();
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(CodeSecurity::errorMessage($result['error'])), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Code security read: %1$d repository(ies), %2$d open alert(s), %3$d failure(s).', 'grcmanager'),
            $result['repositories'],
            $result['alerts'],
            $result['failed']
        )), false, $result['failed'] > 0 || $result['repositories'] === 0 ? WARNING : INFO);
    }
    $back('grc-cs-repositories');
}

if (isset($_POST['clear_codesecurity'])) {
    Session::checkRight(CodeSecurity::RIGHTNAME, UPDATE);
    CodeSecurity::clear();
    Session::addMessageAfterRedirect(htmlescape(__('Code security readings deleted.', 'grcmanager')));
    $back('grc-cs-connection');
}

\GlpiPlugin\Grcmanager\Menu::header(
    CodeSecurity::class,
    CodeSecurity::getTypeName(),
    CodeSecurity::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(CodeSecurity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('codesecurity');

CodeSecurity::showPage();

Html::footer();

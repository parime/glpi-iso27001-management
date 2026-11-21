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

// Délais cibles par criticité (jeton CSRF vérifié par GLPI).
if (isset($_POST['save_patch_targets'])) {
    Session::checkRight(PatchCompliance::RIGHTNAME, UPDATE);
    $before = PatchCompliance::getTargets();
    $submitted = $_POST['patch_targets'] ?? [];
    $after = PatchCompliance::setTargets(is_array($submitted) ? $submitted : []);
    if ($after !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record(
            'config_change',
            'Config',
            0,
            0,
            'success',
            ['changes' => [PatchCompliance::TARGETS_KEY => $after]]
        );
    }
    Session::addMessageAfterRedirect(htmlescape(__('Target delays saved.', 'grcmanager')));
    Html::redirect(PatchCompliance::getSearchURL() . '#grc-patch-targets');
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

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

use GlpiPlugin\Grcmanager\DiskEncryption;

// Issue #847 : chiffrement des disques du parc (A.8.24, A.8.1). Droit du plugin et lecture native des
// ordinateurs ; ordinateurs des entités de la session seulement.
Session::checkRight(DiskEncryption::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

// Issue #1044 : ce réglage est dans l'onglet Parc de la configuration du plugin. L'ancien envoi
// reste accepté pendant une version : enregistré, puis renvoi sur l'onglet avec un message.
if (isset($_POST['save_encryption_rule'])) {
    \GlpiPlugin\Grcmanager\Config\Block\DiskEncryptionBlock::handle(true);
}

// Sauvegarde déclarée de la clé de récupération : une case et une date, jamais la clé.
if (isset($_POST['declare_key'])) {
    Session::checkRight(DiskEncryption::RIGHTNAME, UPDATE);
    if (
        !DiskEncryption::declareKey(
            (int) ($_POST['computers_id'] ?? 0),
            !empty($_POST['is_backed_up']),
            $_POST['backup_date'] ?? ''
        )
    ) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Recovery key declaration saved.', 'grcmanager')));
    Html::redirect(DiskEncryption::getSearchURL() . '#grc-encryption-list');
}

Html::header(
    DiskEncryption::getTypeName(),
    DiskEncryption::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    DiskEncryption::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    DiskEncryption::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('diskencryption');

DiskEncryption::showPage();

Html::footer();

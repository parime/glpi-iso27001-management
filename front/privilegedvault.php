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

use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\PrivilegedVault;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

// Issue #859 : comptes à privilèges et coffre-fort de mots de passe (A.5.17, A.8.2). Droit du plugin
// et lecture native des utilisateurs ; imports dans les entités de la session seulement.
Session::checkRight(PrivilegedVault::RIGHTNAME, READ);
Session::checkRight(User::$rightname, READ);

// Import CSV de métadonnées (jeton CSRF vérifié par GLPI).
if (isset($_POST['import_vault'])) {
    Session::checkRight(PrivilegedVault::RIGHTNAME, UPDATE);
    $file = $_FILES['vault_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            PrivilegedVaultRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = PrivilegedVault::import(
            (string) ($_POST['source'] ?? ''),
            (string) ($_POST['kind'] ?? ''),
            (string) file_get_contents($file['tmp_name'])
        );
        foreach ($result['errors'] as $code) {
            Session::addMessageAfterRedirect(
                htmlescape(PrivilegedVault::importErrorMessage($code, $result['secret'])),
                false,
                ERROR
            );
        }
        if ($result['errors'] === []) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%d line(s) imported.', 'grcmanager'),
                $result['imported']
            )), false, $result['imported'] > 0 ? INFO : WARNING);
        }
    }
    Html::redirect(PrivilegedVault::getSearchURL() . '#grc-vault-import');
}

if (isset($_POST['clear_vault'])) {
    Session::checkRight(PrivilegedVault::RIGHTNAME, UPDATE);
    PrivilegedVault::clear((string) ($_POST['source'] ?? ''), (string) ($_POST['kind'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(PrivilegedVault::getSearchURL() . '#grc-vault-import');
}

// Lecture des membres par l'API publique de Bitwarden, sur clic, droit de modifier.
if (isset($_POST['fetch_bitwarden'])) {
    Session::checkRight(PrivilegedVault::RIGHTNAME, UPDATE);
    $result = PrivilegedVault::fetchFromBitwarden();
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(PrivilegedVault::apiErrorMessage($result['error'])), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('%d member(s) read from Bitwarden.', 'grcmanager'),
            $result['imported']
        )), false, $result['imported'] > 0 ? INFO : WARNING);
    }
    Html::redirect(PrivilegedVault::getSearchURL() . '#grc-vault-bitwarden');
}

\GlpiPlugin\Grcmanager\Menu::header(
    PrivilegedVault::class,
    PrivilegedVault::getTypeName(),
    PrivilegedVault::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(PrivilegedVault::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('privilegedvault');

PrivilegedVault::showPage();

Html::footer();

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
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

// Issue #852 : couverture MFA par compte (A.8.5, A.5.17). Droit du plugin et lecture native des
// utilisateurs ; comptes habilités dans les entités de la session seulement.
Session::checkRight(MfaCoverage::RIGHTNAME, READ);
Session::checkRight(User::$rightname, READ);

// Import CSV d'un fournisseur d'identité (jeton CSRF vérifié par GLPI).
if (isset($_POST['import_mfa'])) {
    Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
    $file = $_FILES['mfa_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            MfaCoverageRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = MfaCoverage::import(
            (string) ($_POST['provider'] ?? ''),
            (string) file_get_contents($file['tmp_name'])
        );
        foreach ($result['errors'] as $code) {
            Session::addMessageAfterRedirect(htmlescape(MfaCoverage::importErrorMessage($code)), false, ERROR);
        }
        if ($result['errors'] === []) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%1$d account(s) imported: %2$d matched with a GLPI account, %3$d not matched.', 'grcmanager'),
                $result['imported'],
                $result['matched'],
                $result['unmatched']
            )), false, $result['imported'] > 0 ? INFO : WARNING);
        }
    }
    Html::redirect(MfaCoverage::getSearchURL() . '#grc-mfa-import');
}

if (isset($_POST['clear_mfa'])) {
    Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
    MfaCoverage::clear((string) ($_POST['provider'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(MfaCoverage::getSearchURL() . '#grc-mfa-import');
}

// Issue #1043 : l'application Microsoft Graph (onglet Connecteurs) et les groupes d'accès distant
// (onglet Personnes et politiques) se règlent dans la configuration. L'ancien envoi de cette page reste
// accepté pendant une version et y mène.
\GlpiPlugin\Grcmanager\Config\Block\EntraGraphConnectorBlock::handleLegacyPost();
\GlpiPlugin\Grcmanager\Config\Block\MfaRemoteAccessBlock::handleLegacyPost();

// Lecture d'Entra ID par Microsoft Graph (#852), sur clic, droit de modifier.
if (isset($_POST['fetch_mfa_graph'])) {
    Session::checkRight(MfaCoverage::RIGHTNAME, UPDATE);
    $result = MfaCoverage::importFromGraph();
    if ($result['graph_error'] !== '') {
        Session::addMessageAfterRedirect(
            htmlescape(MfaCoverage::graphErrorMessage($result['graph_error'])),
            false,
            ERROR
        );
    } elseif ($result['errors'] === []) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('%1$d account(s) imported: %2$d matched with a GLPI account, %3$d not matched.', 'grcmanager'),
            $result['imported'],
            $result['matched'],
            $result['unmatched']
        )), false, $result['imported'] > 0 ? INFO : WARNING);
    }
    Html::redirect(MfaCoverage::getSearchURL() . '#grc-mfa-graph');
}

\GlpiPlugin\Grcmanager\Menu::header(
    MfaCoverage::class,
    MfaCoverage::getTypeName(),
    MfaCoverage::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(MfaCoverage::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('mfacoverage');

MfaCoverage::showPage();

Html::footer();

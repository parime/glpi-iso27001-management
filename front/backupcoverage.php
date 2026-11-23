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

use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

// Issue #838 : couverture des sauvegardes (A.8.13, A.5.30). Droit du plugin et lecture native des
// ordinateurs ; actifs et imports des entités de la session seulement. Aucune sauvegarde n'est lancée.
Session::checkRight(BackupCoverage::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

// Import CSV d'un outil de sauvegarde (jeton CSRF vérifié par GLPI).
if (isset($_POST['import_backup_hosts'])) {
    Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
    $file = $_FILES['backup_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            BackupCoverageRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = BackupCoverage::import(
            (string) ($_POST['source'] ?? ''),
            (string) file_get_contents($file['tmp_name'])
        );
        foreach ($result['errors'] as $code) {
            Session::addMessageAfterRedirect(htmlescape(BackupCoverage::importErrorMessage($code)), false, ERROR);
        }
        if ($result['errors'] === []) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%1$d machine(s) imported: %2$d matched with a GLPI asset, %3$d not matched.', 'grcmanager'),
                $result['imported'],
                $result['matched'],
                $result['unmatched']
            )), false, $result['imported'] > 0 ? INFO : WARNING);
        }
    }
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-import');
}

if (isset($_POST['clear_backup_hosts'])) {
    Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
    BackupCoverage::clear((string) ($_POST['source'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-import');
}

// Issue #1042 : la connexion à Veeam (onglet Connecteurs) et les actifs à sauvegarder (onglet Parc)
// se règlent dans la configuration. L'ancien envoi de cette page reste accepté pendant une version et y mène.
\GlpiPlugin\Grcmanager\Config\Block\VeeamConnectorBlock::handleLegacyPost();
\GlpiPlugin\Grcmanager\Config\Block\BackupSettingsBlock::handleLegacyPost();

// Lecture de Veeam sur clic, droit de modifier.
if (isset($_POST['fetch_veeam'])) {
    Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
    $result = BackupCoverage::importFromVeeam();
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(
            htmlescape(BackupCoverage::veeamErrorMessage($result['error'])),
            false,
            ERROR
        );
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __(
                '%1$d machine(s) read from Veeam: %2$d matched with a GLPI asset, %3$d not matched; %4$d job(s).',
                'grcmanager'
            ),
            $result['imported'],
            $result['matched'],
            $result['unmatched'],
            $result['jobs']
        )), false, $result['imported'] > 0 ? INFO : WARNING);
    }
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-veeam');
}

\GlpiPlugin\Grcmanager\Menu::header(
    BackupCoverage::class,
    BackupCoverage::getTypeName(),
    BackupCoverage::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(BackupCoverage::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('backupcoverage');

BackupCoverage::showPage();

Html::footer();

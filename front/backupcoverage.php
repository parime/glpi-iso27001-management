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
use GlpiPlugin\Grcmanager\Services\Backup\VeeamConfig;
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

// Connecteur Veeam : réglages et lecture sur clic, droit de modifier. Le mot de passe n'est jamais
// renvoyé au navigateur ni écrit dans un journal.
if (isset($_POST['save_veeam'])) {
    Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
    $saved = VeeamConfig::save(
        (string) ($_POST['veeam_url'] ?? ''),
        (string) ($_POST['veeam_username'] ?? ''),
        (string) ($_POST['veeam_password'] ?? ''),
        (string) ($_POST['veeam_api_version'] ?? ''),
        !empty($_POST['veeam_clear_password'])
    );
    if (!$saved['saved']) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Not saved: the address must start with https:// (for example https://veeam.example.test:9419) and '
                . 'contain no user name or password.',
            'grcmanager'
        )), false, ERROR);
    } else {
        if ($saved['changes'] !== []) {
            \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => $saved['changes'],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Veeam settings saved.', 'grcmanager')));
    }
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-veeam');
}

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
            __('%1$d machine(s) read from Veeam: %2$d matched with a GLPI asset, %3$d not matched; %4$d job(s).', 'grcmanager'),
            $result['imported'],
            $result['matched'],
            $result['unmatched'],
            $result['jobs']
        )), false, $result['imported'] > 0 ? INFO : WARNING);
    }
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-veeam');
}

// Réglages : actifs concernés, ancienneté admise sans RPO, fréquence des tests de restauration.
if (isset($_POST['save_backup_settings'])) {
    Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
    $before = BackupCoverage::getSettings();
    $after = BackupCoverage::setSettings([
        'scope'          => (string) ($_POST['backup_scope'] ?? ''),
        'max_age_hours'  => $_POST['backup_max_age_hours'] ?? '',
        'restore_months' => $_POST['backup_restore_months'] ?? '',
    ]);
    if ($after !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record(
            'config_change',
            'Config',
            0,
            0,
            'success',
            ['changes' => [BackupCoverage::SETTINGS_KEY => $after]]
        );
    }
    Session::addMessageAfterRedirect(htmlescape(__('Backup settings saved.', 'grcmanager')));
    Html::redirect(BackupCoverage::getSearchURL() . '#grc-backup-settings');
}

Html::header(
    BackupCoverage::getTypeName(),
    BackupCoverage::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    BackupCoverage::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    BackupCoverage::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('backupcoverage');

BackupCoverage::showPage();

Html::footer();

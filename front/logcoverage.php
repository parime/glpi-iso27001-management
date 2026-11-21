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
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules;

// Issue #853 : couverture de la journalisation (A.8.15, A.8.16, A.8.17). Droit du plugin et lecture
// native des ordinateurs ; actifs et imports des entités de la session seulement.
Session::checkRight(LogCoverage::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

// Import CSV des hôtes émetteurs d'une plateforme de journaux (jeton CSRF vérifié par GLPI).
if (isset($_POST['import_log_hosts'])) {
    Session::checkRight(LogCoverage::RIGHTNAME, UPDATE);
    $file = $_FILES['log_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            LogCoverageRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = LogCoverage::import(
            (string) ($_POST['platform'] ?? ''),
            (string) file_get_contents($file['tmp_name'])
        );
        foreach ($result['errors'] as $code) {
            Session::addMessageAfterRedirect(htmlescape(LogCoverage::importErrorMessage($code)), false, ERROR);
        }
        if ($result['errors'] === []) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%1$d host(s) imported: %2$d matched with a GLPI asset, %3$d not matched.', 'grcmanager'),
                $result['imported'],
                $result['matched'],
                $result['unmatched']
            )), false, $result['imported'] > 0 ? INFO : WARNING);
        }
    }
    Html::redirect(LogCoverage::getSearchURL() . '#grc-log-import');
}

if (isset($_POST['clear_log_hosts'])) {
    Session::checkRight(LogCoverage::RIGHTNAME, UPDATE);
    LogCoverage::clear((string) ($_POST['platform'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(LogCoverage::getSearchURL() . '#grc-log-import');
}

// Réglages : actifs concernés, conservation exigée par la politique, seuil « plus rien depuis ».
if (isset($_POST['save_log_settings'])) {
    Session::checkRight(LogCoverage::RIGHTNAME, UPDATE);
    $before = LogCoverage::getSettings();
    $after = LogCoverage::setSettings([
        'scope'       => (string) ($_POST['log_scope'] ?? ''),
        'policy_days' => $_POST['log_policy_days'] ?? '',
        'stale_days'  => $_POST['log_stale_days'] ?? '',
    ]);
    if ($after !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record(
            'config_change',
            'Config',
            0,
            0,
            'success',
            ['changes' => [LogCoverage::SETTINGS_KEY => $after]]
        );
    }
    Session::addMessageAfterRedirect(htmlescape(__('Logging settings saved.', 'grcmanager')));
    Html::redirect(LogCoverage::getSearchURL() . '#grc-log-settings');
}

// Conservation, obligation et horloges déclarées pour une plateforme (entité active).
if (isset($_POST['declare_log_platform'])) {
    Session::checkRight(LogCoverage::RIGHTNAME, UPDATE);
    if (!LogCoverage::declarePlatform($_POST)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Log platform declaration saved.', 'grcmanager')));
    Html::redirect(LogCoverage::getSearchURL() . '#grc-log-retention');
}

Html::header(
    LogCoverage::getTypeName(),
    LogCoverage::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    LogCoverage::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    LogCoverage::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('logcoverage');

LogCoverage::showPage();

Html::footer();

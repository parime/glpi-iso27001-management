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
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules;

// Issue #843 : contrôleurs de gestion des serveurs (iLO, iDRAC, XClarity, IPMI). Droit du plugin et
// lecture native des ordinateurs ; contrôleurs des entités de la session seulement. Redfish en
// lecture seule : aucune action n'est envoyée aux serveurs. Jeton CSRF vérifié par GLPI.
Session::checkRight(ServerManagement::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

$back = static function (string $anchor, string $query = ''): void {
    Html::redirect(ServerManagement::getSearchURL() . $query . '#' . $anchor);
};
// Contrôleur demandé hors des entités de la session (ou inexistant) : refus, sans rien révéler.
$visible = static function (int $id): void {
    if (ServerManagement::controller($id) === null) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
};

// Déclaration ou modification d'un contrôleur (le mot de passe n'est jamais réaffiché).
if (isset($_POST['save_bmc'])) {
    Session::checkRight(ServerManagement::RIGHTNAME, UPDATE);
    $id = (int) ($_POST['bmc_id'] ?? 0);
    if ($id > 0) {
        $visible($id);
    }
    $saved = ServerManagement::saveController([
        'name'              => $_POST['bmc_name'] ?? '',
        'url'               => $_POST['bmc_url'] ?? '',
        'username'          => $_POST['bmc_username'] ?? '',
        'password'          => $_POST['bmc_password'] ?? '',
        'allow_self_signed' => !empty($_POST['bmc_allow_self_signed']),
        'computers_id'      => $_POST['computers_id'] ?? 0,
    ], $id);
    if ($saved['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(ServerManagement::errorMessage($saved['error'])), false, ERROR);
        $back('grc-bmc-form', $id > 0 ? '?edit=' . $id : '');
    }
    Session::addMessageAfterRedirect(htmlescape(__('Controller saved. Click "Read now" to read it.', 'grcmanager')));
    $back('grc-bmc-' . $saved['id']);
}

if (isset($_POST['read_bmc'])) {
    Session::checkRight(ServerManagement::RIGHTNAME, UPDATE);
    $id = (int) $_POST['read_bmc'];
    $visible($id);
    $result = ServerManagement::read($id);
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(ServerManagement::errorMessage($result['error'])), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape($result['accounts_read']
            ? sprintf(__('Controller read: %d local account(s).', 'grcmanager'), $result['accounts'])
            : __('Controller read. Its local accounts could not be read with this account.', 'grcmanager')));
    }
    $back('grc-bmc-' . $id);
}

if (isset($_POST['delete_bmc'])) {
    Session::checkRight(ServerManagement::RIGHTNAME, UPDATE);
    $visible((int) $_POST['delete_bmc']);
    $ok = ServerManagement::deleteController((int) $_POST['delete_bmc']);
    Session::addMessageAfterRedirect(
        htmlescape($ok ? __('Controller deleted.', 'grcmanager') : ServerManagement::errorMessage('not_found')),
        false,
        $ok ? INFO : ERROR
    );
    $back('grc-bmc-list');
}

// Import CSV des contrôleurs sans Redfish (IPMI seul, export d'un outil du constructeur).
if (isset($_POST['import_bmc'])) {
    Session::checkRight(ServerManagement::RIGHTNAME, UPDATE);
    $file = $_FILES['bmc_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            BmcRules::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = ServerManagement::importCsv((string) file_get_contents($file['tmp_name']));
        foreach ($result['errors'] as $code) {
            Session::addMessageAfterRedirect(htmlescape(ServerManagement::errorMessage($code)), false, ERROR);
        }
        if ($result['errors'] === []) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%1$d controller(s) imported, %2$d updated, %3$d ignored (read by Redfish).', 'grcmanager'),
                $result['imported'],
                $result['updated'],
                $result['skipped']
            )), false, $result['imported'] + $result['updated'] > 0 ? INFO : WARNING);
        }
    }
    $back('grc-bmc-import');
}

\GlpiPlugin\Grcmanager\Menu::header(
    ServerManagement::class,
    ServerManagement::getTypeName(),
    ServerManagement::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(ServerManagement::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('servermanagement');

ServerManagement::showPage((int) ($_GET['edit'] ?? 0));

Html::footer();

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

use GlpiPlugin\Grcmanager\ScannerImport;

// Issue #543 : rapports de scanners de vulnérabilités (Greenbone / OpenVAS), Parc > Sécurité du parc
// > Vulnérabilités. Droit du plugin et lecture native des ordinateurs ; imports des entités de la
// session seulement. Le jeton CSRF est contrôlé par GLPI sur tout POST. Le fichier reçu n'est jamais
// conservé.
Session::checkRight(ScannerImport::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

if (isset($_GET['example'])) {
    // Un seul exemple pour l'instant ; le nom du fichier ne dépend jamais de la valeur reçue.
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report-openvas-exemple.test.xml"');
    readfile(dirname(__DIR__) . '/resources/openvas-example.xml');

    return;
}

$page = ScannerImport::getSearchURL();

if (isset($_POST['import_scanner'])) {
    Session::checkRight(ScannerImport::RIGHTNAME, UPDATE);
    $file = $_FILES['scanner_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $tooLarge = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        Session::addMessageAfterRedirect(htmlescape($tooLarge
            ? __('The file is too large.', 'grcmanager')
            : __('The file could not be received.', 'grcmanager')), false, ERROR);
        Html::redirect($page);
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xml') {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Choose the XML report downloaded from Greenbone (OpenVAS).',
            'grcmanager'
        )), false, ERROR);
        Html::redirect($page);
    }
    if ((int) $file['size'] > ScannerImport::maxUploadBytes()) {
        Session::addMessageAfterRedirect(htmlescape(__('The file is too large.', 'grcmanager')), false, ERROR);
        Html::redirect($page);
    }
    // Lu directement depuis le fichier temporaire de PHP, supprimé par PHP à la fin de la requête.
    $result = ScannerImport::importFile((string) $file['tmp_name'], (string) $file['name']);
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(htmlescape(ScannerImport::errorMessage($result['error'])), false, ERROR);
        Html::redirect($page);
    }
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __(
            'Report imported: %1$d finding(s) on %2$d host(s), %3$d host(s) not matched to a computer, '
                . '%4$d finding(s) fixed since the previous scan.',
            'grcmanager'
        ),
        $result['findings'],
        $result['hosts'],
        $result['unmatched'],
        $result['resolved']
    )), false, $result['unmatched'] > 0 ? WARNING : INFO);
    if ($result['replayed']) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'This report had already been imported: its findings were updated, without duplicates.',
            'grcmanager'
        )));
    }
    if ($result['truncated']) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The file holds more findings than the import keeps: only the first ones were kept.',
            'grcmanager'
        )), false, WARNING);
    }
    Html::redirect($page);
}

if (isset($_POST['link_scanner_host'])) {
    Session::checkRight(ScannerImport::RIGHTNAME, UPDATE);
    if (!ScannerImport::linkHost((string) ($_POST['host'] ?? ''), (int) ($_POST['computers_id'] ?? 0))) {
        Session::addMessageAfterRedirect(htmlescape(__('Choose a computer you can see.', 'grcmanager')), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('Host matched to the computer.', 'grcmanager')));
    }
    Html::redirect($page . '#grc-scanner-unmatched');
}

if (isset($_POST['delete_scanner_import'])) {
    Session::checkRight(ScannerImport::RIGHTNAME, UPDATE);
    if (!ScannerImport::deleteImport((int) ($_POST['import'] ?? 0))) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect($page . '#grc-scanner-history');
}

if (isset($_POST['scanner_ticket'])) {
    Session::checkRight(ScannerImport::RIGHTNAME, UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    if (ScannerImport::getFinding((int) ($_POST['finding'] ?? 0)) === null) {
        // Constat absent ou d'une autre entité : même réponse.
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $ticketId = ScannerImport::createTicket((int) $_POST['finding']);
    if ($ticketId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketId));
    }
    Session::addMessageAfterRedirect(htmlescape(__('The ticket could not be created.', 'grcmanager')), false, ERROR);
    Html::redirect($page);
}

\GlpiPlugin\Grcmanager\Menu::header(ScannerImport::class, ScannerImport::getTypeName(), $page);
// Rubrique Parc > Sécurité du parc : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(ScannerImport::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('scannerimport');

ScannerImport::showPage([
    'status'       => is_string($_GET['status'] ?? null) ? $_GET['status'] : 'open',
    'severity'     => is_string($_GET['severity'] ?? null) ? $_GET['severity'] : '',
    'computers_id' => (int) ($_GET['computers_id'] ?? 0),
]);

Html::footer();

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

use GlpiPlugin\Grcmanager\ConfigurationCompliance;

// Issue #781 : conformité de configuration (A.8.9) d'après les résultats OpenSCAP (XCCDF/ARF). Droit
// du plugin et lecture native des ordinateurs ; rapports des entités de la session seulement. Le
// jeton CSRF est contrôlé par GLPI sur tout POST. Le fichier reçu n'est jamais conservé.
Session::checkRight(ConfigurationCompliance::RIGHTNAME, READ);
Session::checkRight(Computer::$rightname, READ);

if (isset($_GET['example'])) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="openscap-results-example.xml"');
    readfile(dirname(__DIR__) . '/resources/openscap-results-example.xml');

    return;
}

$page = ConfigurationCompliance::getSearchURL();

if (isset($_POST['upload'])) {
    Session::checkRight(ConfigurationCompliance::RIGHTNAME, UPDATE);
    $file = $_FILES['scan_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $tooLarge = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        Session::addMessageAfterRedirect(htmlescape($tooLarge
            ? __('The file is too large.', 'grcmanager')
            : __('The file could not be received.', 'grcmanager')), false, ERROR);
        Html::redirect($page);
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'xml') {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Choose an XML results file produced by OpenSCAP (XCCDF or ARF).',
            'grcmanager'
        )), false, ERROR);
        Html::redirect($page);
    }
    if ((int) $file['size'] > ConfigurationCompliance::maxUploadBytes()) {
        Session::addMessageAfterRedirect(htmlescape(__('The file is too large.', 'grcmanager')), false, ERROR);
        Html::redirect($page);
    }
    // Lu directement depuis le fichier temporaire de PHP, supprimé par PHP à la fin de la requête.
    $result = ConfigurationCompliance::importFile((string) $file['tmp_name'], (string) $file['name']);
    if ($result['error'] !== '') {
        Session::addMessageAfterRedirect(
            htmlescape(ConfigurationCompliance::errorMessage($result['error'])),
            false,
            ERROR
        );
        Html::redirect($page);
    }
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __(
            '%1$d scan result(s) imported: %2$d matched to a computer, %3$d not matched, %4$d already imported.',
            'grcmanager'
        ),
        $result['reports'],
        $result['matched'],
        $result['unmatched'],
        $result['duplicates']
    )), false, $result['unmatched'] > 0 ? WARNING : INFO);
    if ($result['truncated']) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The file holds more results or failed rules than the import keeps: only the first ones were kept.',
            'grcmanager'
        )), false, WARNING);
    }
    Html::redirect($page);
}

if (isset($_POST['link_computer'])) {
    Session::checkRight(ConfigurationCompliance::RIGHTNAME, UPDATE);
    if (!ConfigurationCompliance::linkComputer((int) ($_POST['report'] ?? 0), (int) ($_POST['computers_id'] ?? 0))) {
        Session::addMessageAfterRedirect(htmlescape(__('Choose a computer you can see.', 'grcmanager')), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('Scan result matched to the computer.', 'grcmanager')));
    }
    Html::redirect($page . '#grc-config-unmatched');
}

if (isset($_POST['delete_report'])) {
    Session::checkRight(ConfigurationCompliance::RIGHTNAME, UPDATE);
    if (!ConfigurationCompliance::deleteReport((int) ($_POST['report'] ?? 0))) {
        Html::displayRightError();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Scan result deleted.', 'grcmanager')));
    Html::redirect($page);
}

if (isset($_POST['create_ticket'])) {
    Session::checkRight(ConfigurationCompliance::RIGHTNAME, UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    $ticketId = ConfigurationCompliance::createTicket((int) ($_POST['report'] ?? 0));
    if ($ticketId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('No gap to handle: the ticket was not created.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
}

$reportId = (int) ($_GET['report'] ?? 0);
if ($reportId > 0 && ConfigurationCompliance::getReport($reportId) === null) {
    // Rapport absent ou d'une autre entité : même réponse.
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

Html::header(
    ConfigurationCompliance::getTypeName(),
    $page,
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    ConfigurationCompliance::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    ConfigurationCompliance::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('configcompliance');

if ($reportId > 0) {
    ConfigurationCompliance::showReport($reportId);
} else {
    ConfigurationCompliance::showPage();
}

Html::footer();

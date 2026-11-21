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

use GlpiPlugin\Grcmanager\AdSecurityAudit;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\Services\Access\PingCastleReport;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;

// Issue #835 : audit de sécurité de l'Active Directory (PingCastle, Purple Knight) et de la
// configuration d'Entra ID et de Microsoft 365 (Maester, ScubaGear), registre sensible ; imports des
// entités de la session seulement. Le plugin ne lance aucun outil.
Session::checkRight(AdSecurityAudit::RIGHTNAME, READ);

// Rapports d'exemple fictifs (domaine exemple.test), pour essayer l'import : PingCastle par défaut
// (?example=1), sinon l'outil demandé parmi ceux de la page (liste fermée, jamais un chemin reçu).
if (isset($_GET['example'])) {
    $tools = AdSecurityAudit::toolInfos();
    $tool = is_string($_GET['example']) && isset($tools[$_GET['example']]) ? $_GET['example'] : 'pingcastle';
    $info = $tools[$tool];
    $type = str_ends_with($info['example'], '.json')
        ? 'application/json'
        : (str_ends_with($info['example'], '.csv') ? 'text/csv' : 'application/xml');
    header('Content-Type: ' . $type . '; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $info['file'] . '"');
    readfile(dirname(__DIR__) . '/resources/' . $info['example']);
    exit;
}

// Import d'un rapport (jeton CSRF vérifié par GLPI).
if (isset($_POST['import_ad_audit'])) {
    Session::checkRight(AdSecurityAudit::RIGHTNAME, UPDATE);
    $file = $_FILES['ad_audit_file'] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            PingCastleReport::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    $id = 0;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );
    } else {
        $result = AdSecurityAudit::import(
            (string) file_get_contents($file['tmp_name']),
            is_string($file['name'] ?? null) ? $file['name'] : '',
            !empty($_POST['ad_audit_archive']),
            is_string($_POST['ad_audit_domain'] ?? null) ? $_POST['ad_audit_domain'] : ''
        );
        if ($result['error'] !== '') {
            Session::addMessageAfterRedirect(
                htmlescape(AdSecurityAudit::importErrorMessage($result['error'])),
                false,
                ERROR
            );
        } else {
            $id = $result['id'];
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Report imported: %d rule(s) triggered.', 'grcmanager'),
                $result['rules']
            )));
            if ($result['secrets']) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'The report contains passwords or secrets (for example in group policies): they were '
                        . 'not kept, and are removed from the archived copy. Change them as soon as possible.',
                    'grcmanager'
                )), false, WARNING);
            }
            if (!empty($_POST['ad_audit_archive']) && $result['tool'] === 'pingcastle' && !$result['archived']) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'The report could not be archived as a document (XML files may not be allowed in the '
                        . 'document types of GLPI).',
                    'grcmanager'
                )), false, WARNING);
            }
            if ($result['truncated']) {
                Session::addMessageAfterRedirect(htmlescape(
                    $result['tool'] === 'pingcastle'
                        ? sprintf(__('Only the first %d rules were read.', 'grcmanager'), PingCastleReport::MAX_RULES)
                        : __('The file is very long: only its first checks were read.', 'grcmanager')
                ), false, WARNING);
            }
        }
    }
    Html::redirect(AdSecurityAudit::getSearchURL() . ($id > 0 ? '?id=' . $id : '') . '#grc-ad-import');
}

if (isset($_POST['clear_ad_audit'])) {
    Session::checkRight(AdSecurityAudit::RIGHTNAME, UPDATE);
    if (!AdSecurityAudit::clear((int) ($_POST['audit_id'] ?? 0))) {
        Html::displayRightError();
    }
    Session::addMessageAfterRedirect(htmlescape(__('Import deleted.', 'grcmanager')));
    Html::redirect(AdSecurityAudit::getSearchURL() . '#grc-ad-history');
}

// Ticket proposé pour une règle, sur clic seulement.
if (isset($_POST['ad_audit_ticket'])) {
    Session::checkRight(AdSecurityAudit::RIGHTNAME, UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    $ruleId = (int) ($_POST['rule_id'] ?? 0);
    $found = AdSecurityAudit::getRule($ruleId);
    if ($found === null) {
        // Règle d'un import hors des entités de la session.
        Html::displayRightError();
    }
    $ticketId = AdSecurityAudit::createTicket($ruleId);
    if ($ticketId <= 0) {
        Session::addMessageAfterRedirect(
            htmlescape(__('The ticket could not be created.', 'grcmanager')),
            false,
            ERROR
        );
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(__('Ticket %d created.', 'grcmanager'), $ticketId)));
    }
    $auditId = $found === null ? 0 : (int) $found['audit']['id'];
    Html::redirect(AdSecurityAudit::getSearchURL() . ($auditId > 0 ? '?id=' . $auditId : '') . '#grc-ad-rules');
}

Html::header(
    AdSecurityAudit::getTypeName(),
    AdSecurityAudit::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    AdSecurityAudit::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    AdSecurityAudit::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('adsecurityaudit');

AdSecurityAudit::showPage((int) ($_GET['id'] ?? 0));

Html::footer();

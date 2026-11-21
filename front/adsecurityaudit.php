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

// Issue #835 : audit de sécurité de l'Active Directory (rapports PingCastle), registre sensible ;
// imports des entités de la session seulement. Le plugin ne lance aucun outil.
Session::checkRight(AdSecurityAudit::RIGHTNAME, READ);

// Rapport d'exemple fictif (domaine exemple.test), pour essayer l'import.
if (isset($_GET['example'])) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ad_hc_exemple.test.xml"');
    readfile(dirname(__DIR__) . '/resources/pingcastle-example.xml');
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
            !empty($_POST['ad_audit_archive'])
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
            if (!empty($_POST['ad_audit_archive']) && !$result['archived']) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'The report could not be archived as a document (XML files may not be allowed in the '
                        . 'document types of GLPI).',
                    'grcmanager'
                )), false, WARNING);
            }
            if ($result['truncated']) {
                Session::addMessageAfterRedirect(htmlescape(sprintf(
                    __('Only the first %d rules were read.', 'grcmanager'),
                    PingCastleReport::MAX_RULES
                )), false, WARNING);
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

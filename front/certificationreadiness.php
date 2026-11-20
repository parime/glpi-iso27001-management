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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #619 : préparation à la certification ISO 27001 (étapes, état calculé, lien vers l'écran
// à compléter, score, export PDF). L'état est calculé, jamais modifié ; seuls les réglages de
// l'issue #761 sont enregistrés.
Session::checkRight(CertificationReadiness::RIGHTNAME, READ);

// Issue #761 : réglages (date cible, date de certification, responsable, seuils). Droit de
// modifier le plugin ; jeton CSRF vérifié par GLPI.
if (isset($_POST['save_readiness_settings'])) {
    Session::checkRight(CertificationReadiness::RIGHTNAME, UPDATE);
    CertificationReadiness::saveSettings($_POST);
    Session::addMessageAfterRedirect(__('Certification readiness settings saved.', 'grcmanager'));
    Html::redirect(CertificationReadiness::getSearchURL());
}

$report = CertificationReadiness::report();

if (($_GET['format'] ?? '') === 'pdf') {
    $pdf = PdfRenderer::renderHtmlToPdf(CertificationReadiness::renderPdf($report));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="preparation-certification-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

Html::header(
    CertificationReadiness::getTypeName(),
    CertificationReadiness::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Dashboard::class,
    CertificationReadiness::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Dashboard::class,
    CertificationReadiness::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('certificationreadiness');
TemplateRenderer::getInstance()->display('@grcmanager/certification_readiness.html.twig', $report + [
    'styles'   => CertificationReadiness::getStatusStyles(),
    'pdf_url'  => CertificationReadiness::getPdfURL(),
    'can_edit' => CertificationReadiness::canUpdate(),
    'action'   => CertificationReadiness::getSearchURL(),
]);
Html::footer();

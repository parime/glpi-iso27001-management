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
// à compléter, score, export PDF). Lecture seule : rien n'est modifié ni enregistré.
Session::checkRight(CertificationReadiness::RIGHTNAME, READ);

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
    'styles'  => CertificationReadiness::getStatusStyles(),
    'pdf_url' => CertificationReadiness::getPdfURL(),
]);
Html::footer();

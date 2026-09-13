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

use GlpiPlugin\Grcmanager\Services\Control\ControlCsvImportService;

include('../../../inc/includes.php');

// ROADMAP.md "Version 1.5" (import/export de la SoA au format standard) : contrairement au CSV
// natif de Search::showList() (colonne "Contrôle" combinant code+intitulé, libellés non pensés
// pour un aller-retour), ce contrôleur produit/lit un CSV dédié dont les colonnes sont conçues
// pour être réimportées telles quelles.
$titles = PluginGrcmanagerControl::getControlTitles();
$themes = PluginGrcmanagerControl::getThemes();
$applicabilities = PluginGrcmanagerControl::getApplicabilities();
$implementationStatuses = PluginGrcmanagerControl::getImplementationStatuses();

$csvHeader = ['code', 'theme', 'titre', 'applicabilite', 'etat_mise_en_oeuvre', 'justification'];

if (($_GET['action'] ?? '') === 'export') {
    Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

    global $DB;

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="soa-annexe-a-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
    fputcsv($out, $csvHeader, ';');

    foreach ($DB->request(['FROM' => PluginGrcmanagerControl::getTable(), 'ORDER' => 'code ASC']) as $row) {
        fputcsv($out, [
            $row['code'],
            $themes[$row['theme']] ?? $row['theme'],
            $titles[$row['code']] ?? $row['code'],
            $applicabilities[$row['applicability']] ?? $row['applicability'],
            $implementationStatuses[$row['implementation_status']] ?? $row['implementation_status'],
            $row['justification'],
        ], ';');
    }

    fclose($out);
    exit;
}

Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

$importResult = null;

if (isset($_POST['import'])) {
    Session::checkRight(PluginGrcmanagerControl::$rightname, UPDATE);

    $uploadError = $_FILES['soa_file']['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['soa_file']['tmp_name'])) {
        Session::addMessageAfterRedirect(
            __('Aucun fichier valide n\'a été envoyé.', 'grcmanager'),
            false,
            ERROR
        );
    } else {
        $rows = [];
        $handle = fopen($_FILES['soa_file']['tmp_name'], 'r');
        $header = fgetcsv($handle, 0, ';');

        if ($header !== false && $header[0] === "\xEF\xBB\xBF" . 'code') {
            $header[0] = 'code';
        }

        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if ($header === false || count($line) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        global $DB;
        $existingCodes = [];
        foreach ($DB->request(['SELECT' => ['code'], 'FROM' => PluginGrcmanagerControl::getTable()]) as $row) {
            $existingCodes[] = $row['code'];
        }

        $result = ControlCsvImportService::process(
            $rows,
            $existingCodes,
            $applicabilities,
            $implementationStatuses
        );

        foreach ($result['valid'] as $validRow) {
            $DB->update(
                PluginGrcmanagerControl::getTable(),
                [
                    'applicability'         => $validRow['applicability'],
                    'implementation_status' => $validRow['implementation_status'],
                    'justification'         => $validRow['justification'],
                    'is_reviewed'           => 1,
                ],
                ['code' => $validRow['code']]
            );
        }

        $reasonLabels = [
            ControlCsvImportService::REASON_UNKNOWN_CODE            =>
                __('Code inconnu du catalogue Annexe A.', 'grcmanager'),
            ControlCsvImportService::REASON_UNKNOWN_APPLICABILITY   =>
                __('Valeur d\'applicabilité non reconnue.', 'grcmanager'),
            ControlCsvImportService::REASON_UNKNOWN_STATUS          =>
                __('Valeur d\'état de mise en œuvre non reconnue.', 'grcmanager'),
            ControlCsvImportService::REASON_MISSING_JUSTIFICATION   =>
                __('Justification obligatoire : ce contrôle n\'est pas pleinement applicable.', 'grcmanager'),
        ];

        $importResult = [
            'updated_count'  => count($result['valid']),
            'rejected_count' => count($result['rejected']),
            'rejected'       => array_map(
                static fn (array $r) => ['code' => $r['code'], 'reason' => $reasonLabels[$r['reason']] ?? $r['reason']],
                $result['rejected']
            ),
        ];
    }
}

Html::header(
    __('Import/export CSV — Annexe A', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/control_csv.html.twig', [
    'can_edit'      => Session::haveRight(PluginGrcmanagerControl::$rightname, UPDATE),
    'import_result' => $importResult,
]);

Html::footer();

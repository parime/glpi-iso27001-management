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
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

include('../../../inc/includes.php');

// Issue #370 : import d'un export CSV d'EDR / XDR (fichier, correspondance des colonnes, résultat).
Session::checkRight(PluginGrcmanagerSecurityIncident::$rightname, CREATE);

if (isset($_GET['example'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="exemple-import-edr.csv"');
    readfile(dirname(__DIR__) . '/resources/edr-import-example.csv');

    return;
}

$tmpKey = 'grcmanager_edr_import';
$action = SecurityIncidentEdrImport::getImportURL();
$params = ['action' => $action, 'max_mb' => EdrCsvImport::MAX_BYTES / 1048576, 'max_rows' => EdrCsvImport::MAX_ROWS];
$step = 'upload';

if (isset($_POST['upload'])) {
    $file = $_FILES['edr_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        Session::addMessageAfterRedirect(__('The file could not be received.', 'grcmanager'), false, ERROR);
        Html::redirect($action);
    }
    if ((int) $file['size'] > EdrCsvImport::MAX_BYTES) {
        Session::addMessageAfterRedirect(__('The file is too large.', 'grcmanager'), false, ERROR);
        Html::redirect($action);
    }
    $stored = GLPI_TMP_DIR . '/grcmanager-edr-' . bin2hex(random_bytes(12)) . '.csv';
    move_uploaded_file((string) $file['tmp_name'], $stored);
    $_SESSION[$tmpKey] = ['path' => $stored, 'name' => basename((string) $file['name'])];
}

$pending = $_SESSION[$tmpKey] ?? null;
$pendingPath = is_array($pending) ? (string) $pending['path'] : '';
$valid = $pendingPath !== '' && str_starts_with(realpath($pendingPath) ?: '', realpath(GLPI_TMP_DIR) ?: '/nonexistent')
    && is_readable($pendingPath);

if ((isset($_POST['upload']) || isset($_POST['import'])) && !$valid) {
    unset($_SESSION[$tmpKey]);
    Session::addMessageAfterRedirect(__('The file could not be received.', 'grcmanager'), false, ERROR);
    Html::redirect($action);
}

if (isset($_POST['import']) && $valid) {
    $raw = (string) file_get_contents($pendingPath);
    $parsed = EdrCsvImport::parse($raw);
    $mapping = [];
    foreach ((array) ($_POST['mapping'] ?? []) as $field => $index) {
        $known = array_key_exists((string) $field, EdrCsvImport::FIELDS);
        if ($known && $index !== '' && isset($parsed['headers'][(int) $index])) {
            $mapping[(string) $field] = (int) $index;
        }
    }
    $grouping = (string) ($_POST['grouping'] ?? '');
    $grouping = in_array($grouping, EdrCsvImport::GROUPINGS, true) ? $grouping : 'detection';
    $timezone = (string) ($_POST['timezone'] ?? '');
    $timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    $result = SecurityIncidentEdrImport::import(
        $parsed,
        $mapping,
        $grouping,
        $timezone,
        (int) ($_POST['target'] ?? 0),
        (string) $pending['name'],
        hash('sha256', $raw)
    );
    // Chemin créé par le serveur à l'étape « fichier » (nom aléatoire, gardé en session) et vérifié
    // sous GLPI_TMP_DIR ci-dessus : aucune partie ne vient de l'utilisateur.
    // nosemgrep: php.lang.security.unlink-use.unlink-use
    @unlink($pendingPath);
    unset($_SESSION[$tmpKey]);
    $links = [];
    foreach (array_unique(array_merge($result['created'], $result['updated'])) as $incidentId) {
        $incident = new PluginGrcmanagerSecurityIncident();
        if ($incident->getFromDB($incidentId)) {
            $links[] = [
                'url'   => PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId),
                'label' => trim(($incident->fields['reference'] ?? '') . ' ' . $incident->fields['name']),
            ];
        }
    }
    $step = 'result';
    $params += $result + ['links' => $links];
} elseif ($valid && isset($_POST['upload'])) {
    $parsed = EdrCsvImport::parse((string) file_get_contents($pendingPath));
    $incidents = [];
    $incident = new PluginGrcmanagerSecurityIncident();
    $closed = array_merge(
        PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
        PluginGrcmanagerSecurityIncident::getClosedStatusArray()
    );
    foreach ($incident->find(['is_deleted' => 0, 'NOT' => ['status' => $closed]], ['date DESC'], 200) as $row) {
        if ($incident->can((int) $row['id'], UPDATE)) {
            $incidents[(int) $row['id']] = trim(($row['reference'] ?? '') . ' ' . $row['name']);
        }
    }
    $step = 'mapping';
    $params += [
        'file_name'        => (string) $pending['name'],
        'row_count'        => count($parsed['rows']),
        'truncated'        => $parsed['truncated'],
        'headers'          => $parsed['headers'],
        'mapping'          => EdrCsvImport::guessMapping($parsed['headers']),
        'preview'          => array_slice($parsed['rows'], 0, 5),
        'field_labels'     => SecurityIncidentEdrImport::getFieldLabels(),
        'groupings'        => SecurityIncidentEdrImport::getGroupingLabels(),
        'timezones'        => timezone_identifiers_list(),
        'default_timezone' => date_default_timezone_get(),
        'incidents'        => $incidents,
    ];
}

Html::header(
    SecurityIncidentEdrImport::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    PluginGrcmanagerSecurityIncident::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('securityincidentedrimport');
TemplateRenderer::getInstance()->display('@grcmanager/edr_import.html.twig', $params + ['step' => $step]);
Html::footer();

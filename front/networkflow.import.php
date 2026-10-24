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

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;

include('../../../inc/includes.php');

// Issue #144 : import d'une matrice des flux au format CSV, simulation d'abord.
Session::checkRight(PluginGrcmanagerNetworkFlow::$rightname, CREATE);

$result = null;
$simulate = true;
if (isset($_POST['import'])) {
    $simulate = !empty($_POST['simulate']);
    $file = $_FILES['csv_file'] ?? null;
    $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
    if (!$uploaded) {
        $message = __('Choisissez un fichier CSV de 2 Mo au plus.', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect(PluginGrcmanagerNetworkFlow::getImportURL());
    }
    $content = (string) file_get_contents((string) $file['tmp_name']);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $result = PluginGrcmanagerNetworkFlow::importCsv($content, $simulate);
}

$title = __('Importer une matrice des flux', 'grcmanager');
Html::header($title, PluginGrcmanagerNetworkFlow::getSearchURL(), 'grcmanager', PluginGrcmanagerNetworkFlow::class);

echo '<div class="card mb-3"><div class="card-body">';
echo '<h3 class="card-title"><i class="ti ti-file-import me-1"></i>' . htmlescape($title) . '</h3>';
echo '<p>' . htmlescape(__(
    'Fichier CSV séparé par des points-virgules ou des virgules, avec une ligne d\'en-tête. Colonnes reconnues : '
        . 'nom, type, source, destination, protocole, ports, justification, proprietaire (identifiant GLPI), '
        . 'date_fin. Seules source et destination sont obligatoires.',
    'grcmanager'
)) . '</p>';
$example = [
    'nom;type;source;destination;protocole;ports;justification;proprietaire;date_fin',
    __('Site public;publication;Internet;Serveur web;tcp;443;Site vitrine;;', 'grcmanager'),
    __('Sauvegarde;sauvegarde;Site principal;Site de secours;tcp;10000;Réplication nocturne;;', 'grcmanager'),
];
echo '<pre class="bg-light p-2 small mb-3">' . htmlescape(implode("\n", $example)) . '</pre>';
echo '<p class="text-muted small">' . htmlescape(__(
    'Types : interne, publication, sortant, tunnel, partenaire, administration, sauvegarde, autre. Un flux déjà '
        . 'présent (mêmes source, destination, protocole et ports) n\'est pas recréé : réimporter le même fichier '
        . 'ne crée pas de doublon.',
    'grcmanager'
)) . '</p>';

echo '<form method="post" enctype="multipart/form-data" action="'
    . htmlescape(PluginGrcmanagerNetworkFlow::getImportURL()) . '" class="d-flex flex-wrap align-items-center gap-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape(Session::getNewCSRFToken()) . '">';
echo '<input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" style="max-width: 420px;"'
    . ' required>';
echo '<label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="simulate" value="1"'
    . ($simulate ? ' checked' : '') . '><span class="form-check-label">'
    . htmlescape(__('Simulation (rien n\'est enregistré)', 'grcmanager')) . '</span></label>';
echo '<button type="submit" name="import" value="1" class="btn btn-primary"><i class="ti ti-upload me-1"></i>'
    . htmlescape(__('Analyser le fichier', 'grcmanager')) . '</button>';
echo '<a href="' . htmlescape(PluginGrcmanagerNetworkFlow::getSearchURL()) . '" class="btn btn-outline-secondary">'
    . htmlescape(__('Retour à la matrice des flux', 'grcmanager')) . '</a>';
echo '</form></div></div>';

if ($result !== null) {
    echo '<div class="card"><div class="card-body">';
    $summary = $simulate
        ? __('Simulation : %1$d flux seraient créés, %2$d existent déjà.', 'grcmanager')
        : __('Import terminé : %1$d flux créés, %2$d existaient déjà.', 'grcmanager');
    echo '<div class="alert ' . ($simulate ? 'alert-info' : 'alert-success') . '">'
        . htmlescape(sprintf($summary, $result['created'], $result['skipped']));
    if ($simulate && $result['created'] > 0) {
        $next = __('Décochez « Simulation » et analysez à nouveau le fichier pour l\'importer.', 'grcmanager');
        echo '<br>' . htmlescape($next);
    }
    echo '</div>';
    if ($result['errors'] !== []) {
        echo '<div class="alert alert-warning"><ul class="mb-0">';
        foreach ($result['errors'] as $error) {
            $line = sprintf(__('Ligne %1$d : %2$s', 'grcmanager'), $error['line'], $error['message']);
            echo '<li>' . htmlescape($line) . '</li>';
        }
        echo '</ul></div>';
    }
    if ($result['rows'] !== []) {
        echo '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>';
        $headers = [__('Ligne', 'grcmanager'), __('Nom', 'grcmanager'), __('Type', 'grcmanager'),
            __('Source', 'grcmanager'), __('Destination', 'grcmanager'), __('Ports', 'grcmanager'),
            __('Contrôles', 'grcmanager'), ''];
        foreach ($headers as $header) {
            echo '<th>' . htmlescape($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $today = date('Y-m-d');
        foreach ($result['rows'] as $row) {
            // Propriétaire non encore résolu en simulation : seul le fait qu'il soit renseigné compte.
            $checks = NetworkFlowRules::checks($row + ['users_id' => $row['owner'] !== '' ? 1 : 0], $today);
            if ($row['status'] === 'skipped') {
                $state = ['bg-secondary-lt', __('Déjà présent', 'grcmanager')];
            } else {
                $state = ['bg-blue-lt', $simulate ? __('À créer', 'grcmanager') : __('Créé', 'grcmanager')];
            }
            echo '<tr><td>' . (int) $row['line'] . '</td>'
                . '<td>' . htmlescape($row['name']) . '</td>'
                . '<td>' . PluginGrcmanagerNetworkFlow::typeBadge($row['flow_type']) . '</td>'
                . '<td>' . htmlescape($row['source_label']) . '</td>'
                . '<td>' . htmlescape($row['destination_label']) . '</td>'
                . '<td>' . htmlescape(PluginGrcmanagerNetworkFlow::portsLabel($row['ports'])) . '</td>'
                . '<td>' . PluginGrcmanagerNetworkFlow::checksHtml($checks) . '</td>'
                . '<td><span class="badge ' . $state[0] . '">' . htmlescape($state[1]) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
}

Html::footer();

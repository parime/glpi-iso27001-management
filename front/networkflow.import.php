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
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;

include('../../../inc/includes.php');

// Issue #144 : import d'une matrice des flux au format CSV, simulation d'abord.
Session::checkRight(\GlpiPlugin\Grcmanager\NetworkFlow::$rightname, CREATE);

$result = null;
$simulate = true;
if (isset($_POST['import'])) {
    $simulate = !empty($_POST['simulate']);
    $file = $_FILES['csv_file'] ?? null;
    $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
    if (!$uploaded) {
        $message = __('Choose a CSV file or a pfSense backup (XML) of 2 MB at most.', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect(\GlpiPlugin\Grcmanager\NetworkFlow::getImportURL());
    }
    $content = (string) file_get_contents((string) $file['tmp_name']);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $result = \GlpiPlugin\Grcmanager\NetworkFlow::importCsv($content, $simulate);
}

$title = __('Import a network flow matrix', 'grcmanager');
$rows = [];
$today = date('Y-m-d');
foreach ($result['rows'] ?? [] as $row) {
    // Propriétaire non encore résolu en simulation : seul le fait qu'il soit renseigné compte.
    $checks = NetworkFlowRules::checks(
        $row + ['users_id' => $row['owner'] !== '' ? 1 : 0],
        $today,
        \GlpiPlugin\Grcmanager\NetworkFlow::internalNetworks()
    );
    if ($row['status'] === 'skipped') {
        $state = ['bg-secondary-lt', __('Already present', 'grcmanager')];
    } else {
        $state = ['bg-blue-lt', $simulate ? __('To create', 'grcmanager') : __('Created', 'grcmanager')];
    }
    $rows[] = [
        'line'              => (int) $row['line'],
        'name'              => $row['name'],
        // Badges partagés avec la liste des flux, texte déjà échappé.
        'type_html'         => \GlpiPlugin\Grcmanager\NetworkFlow::typeBadge($row['flow_type']),
        'checks_html'       => \GlpiPlugin\Grcmanager\NetworkFlow::checksHtml($checks),
        'source_label'      => $row['source_label'],
        'destination_label' => $row['destination_label'],
        'ports_label'       => \GlpiPlugin\Grcmanager\NetworkFlow::portsLabel($row['ports']),
        'state_class'       => $state[0],
        'state_label'       => $state[1],
    ];
}

Html::header(
    $title,
    \GlpiPlugin\Grcmanager\NetworkFlow::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\NetworkFlow::class
);
TemplateRenderer::getInstance()->display('@grcmanager/network_flow_import.html.twig', [
    'title'    => $title,
    'example'  => implode("\n", [
        'nom;type;source;destination;protocole;ports;justification;proprietaire;date_fin',
        __('Public website;publication;Internet;Web server;tcp;443;Company website;;', 'grcmanager'),
        __('Backup;backup;Main site;Recovery site;tcp;10000;Nightly replication;;', 'grcmanager'),
    ]),
    'action'   => \GlpiPlugin\Grcmanager\NetworkFlow::getImportURL(),
    'list_url' => \GlpiPlugin\Grcmanager\NetworkFlow::getSearchURL(),
    'simulate' => $simulate,
    'result'   => $result,
    'rows'     => $rows,
]);

Html::footer();

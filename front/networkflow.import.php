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
Session::checkRight(PluginGrcmanagerNetworkFlow::$rightname, CREATE);

$result = null;
$simulate = true;
if (isset($_POST['import'])) {
    $simulate = !empty($_POST['simulate']);
    $file = $_FILES['csv_file'] ?? null;
    $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
    if (!$uploaded) {
        $message = __('Choisissez un fichier CSV ou une sauvegarde pfSense (XML) de 2 Mo au plus.', 'grcmanager');
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
$rows = [];
$today = date('Y-m-d');
foreach ($result['rows'] ?? [] as $row) {
    // Propriétaire non encore résolu en simulation : seul le fait qu'il soit renseigné compte.
    $checks = NetworkFlowRules::checks(
        $row + ['users_id' => $row['owner'] !== '' ? 1 : 0],
        $today,
        PluginGrcmanagerNetworkFlow::internalNetworks()
    );
    if ($row['status'] === 'skipped') {
        $state = ['bg-secondary-lt', __('Déjà présent', 'grcmanager')];
    } else {
        $state = ['bg-blue-lt', $simulate ? __('À créer', 'grcmanager') : __('Créé', 'grcmanager')];
    }
    $rows[] = [
        'line'              => (int) $row['line'],
        'name'              => $row['name'],
        // Badges partagés avec la liste des flux, texte déjà échappé.
        'type_html'         => PluginGrcmanagerNetworkFlow::typeBadge($row['flow_type']),
        'checks_html'       => PluginGrcmanagerNetworkFlow::checksHtml($checks),
        'source_label'      => $row['source_label'],
        'destination_label' => $row['destination_label'],
        'ports_label'       => PluginGrcmanagerNetworkFlow::portsLabel($row['ports']),
        'state_class'       => $state[0],
        'state_label'       => $state[1],
    ];
}

Html::header($title, PluginGrcmanagerNetworkFlow::getSearchURL(), 'grcmanager', PluginGrcmanagerNetworkFlow::class);
TemplateRenderer::getInstance()->display('@grcmanager/network_flow_import.html.twig', [
    'title'    => $title,
    'example'  => implode("\n", [
        'nom;type;source;destination;protocole;ports;justification;proprietaire;date_fin',
        __('Site public;publication;Internet;Serveur web;tcp;443;Site vitrine;;', 'grcmanager'),
        __('Sauvegarde;sauvegarde;Site principal;Site de secours;tcp;10000;Réplication nocturne;;', 'grcmanager'),
    ]),
    'action'   => PluginGrcmanagerNetworkFlow::getImportURL(),
    'list_url' => PluginGrcmanagerNetworkFlow::getSearchURL(),
    'simulate' => $simulate,
    'result'   => $result,
    'rows'     => $rows,
]);

Html::footer();

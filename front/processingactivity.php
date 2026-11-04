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
use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

// Issue #375 : registre des activités de traitement (RGPD, article 30). Donnée sensible : droit dédié.
Session::checkRight(ProcessingActivity::$rightname, READ);

Html::header(
    ProcessingActivity::getTypeName(2),
    ProcessingActivity::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    ProcessingActivity::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, ProcessingActivity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('processingactivity');

// Export du registre pour l'autorité de contrôle (PDF) et pour un tableur (CSV).
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [
        [
            'url'     => ProcessingActivity::getExportURL('pdf'),
            'icon'    => 'ti-file-type-pdf',
            'label'   => __('Export PDF', 'grcmanager'),
            'new_tab' => true,
        ],
        [
            'url'   => ProcessingActivity::getExportURL('csv'),
            'icon'  => 'ti-file-type-csv',
            'label' => __('Export CSV', 'grcmanager'),
        ],
    ],
]);

$params = QueryBuilder::manageParams(ProcessingActivity::class, $_GET);

Search::showList(
    ProcessingActivity::class,
    $params,
    DefaultSearchColumns::COLUMNS[ProcessingActivity::class]
);

Html::footer();

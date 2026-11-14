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
use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

// Issue #620 : registre des violations de données personnelles (RGPD, article 33.5). Droit dédié.
Session::checkRight(DataBreach::$rightname, READ);

Html::header(
    __('Breach register', 'grcmanager'),
    DataBreach::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    DataBreach::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, DataBreach::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('databreach');

// Export du registre pour l'autorité de contrôle (PDF) et pour un tableur (CSV).
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [
        [
            'url'     => DataBreach::getExportURL('pdf'),
            'icon'    => 'ti-file-type-pdf',
            'label'   => __('Export PDF', 'grcmanager'),
            'new_tab' => true,
        ],
        [
            'url'   => DataBreach::getExportURL('csv'),
            'icon'  => 'ti-file-type-csv',
            'label' => __('Export CSV', 'grcmanager'),
        ],
    ],
]);

$params = QueryBuilder::manageParams(DataBreach::class, $_GET);

Search::showList(DataBreach::class, $params, DefaultSearchColumns::COLUMNS[DataBreach::class]);

Html::footer();

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
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

Session::checkRight(\GlpiPlugin\Grcmanager\AccessReview::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\AccessReview::getTypeName(2),
    \GlpiPlugin\Grcmanager\AccessReview::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\AccessReview::class
);
PluginGrcmanagerPageHelp::show('accessreview');

TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => \GlpiPlugin\Grcmanager\AccessReview::getMineURL(),
        'icon'  => 'ti-list-check',
        'label' => __('My access reviews', 'grcmanager'),
    ]],
]);

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\AccessReview::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\AccessReview::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\AccessReview::class]
);

Html::footer();

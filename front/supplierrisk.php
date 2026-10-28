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

Session::checkRight(\GlpiPlugin\Grcmanager\SupplierRisk::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\SupplierRisk::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\SupplierRisk::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Risk::class, \GlpiPlugin\Grcmanager\SupplierRisk::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('supplierrisk');

// Owner-scoped default view, same shortcut as front/risk.php (search option id 8, see
// \GlpiPlugin\Grcmanager\SupplierRisk::rawSearchOptions()).
$myRisksURL = \GlpiPlugin\Grcmanager\SupplierRisk::getSearchURL() . '?' . http_build_query([
    'criteria' => [
        ['field' => 8, 'searchtype' => 'equals', 'value' => Session::getLoginUserID()],
    ],
]);
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => $myRisksURL,
        'icon'  => 'ti-user-check',
        'label' => __('My supplier risks', 'grcmanager'),
    ]],
]);

// Same URL-driven search fix as front/risk.php (Search::showList() does not merge $_GET on its
// own, unlike Search::show()), see its own docblock for the full rationale.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\SupplierRisk::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\SupplierRisk::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\SupplierRisk::class]
);

Html::footer();

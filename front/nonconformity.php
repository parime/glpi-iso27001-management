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

Session::checkRight(\GlpiPlugin\Grcmanager\Nonconformity::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Nonconformity::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Audit::class,
    \GlpiPlugin\Grcmanager\Nonconformity::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Audit::class, \GlpiPlugin\Grcmanager\Nonconformity::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('nonconformity');

// Owner-scoped default view (same "Mes ..." shortcut already offered by front/risk.php): a
// one-click link to this same list pre-filtered on the "Responsable" search option (id 4, see
// \GlpiPlugin\Grcmanager\Nonconformity::rawSearchOptions()) to the logged-in user's own ID.
$myCapaURL = \GlpiPlugin\Grcmanager\Nonconformity::getSearchURL() . '?' . http_build_query([
    'criteria' => [
        ['field' => 4, 'searchtype' => 'equals', 'value' => Session::getLoginUserID()],
    ],
]);
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => $myCapaURL,
        'icon'  => 'ti-user-check',
        'label' => __('My corrective/preventive actions', 'grcmanager'),
    ]],
]);

// Same URL-driven search fix as front/risk.php/front/control.php/front/audit.php.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Nonconformity::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Nonconformity::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Nonconformity::class]
);

Html::footer();

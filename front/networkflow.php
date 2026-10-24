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

use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

// Issue #144 : matrice des flux réseau.
Session::checkRight(PluginGrcmanagerNetworkFlow::$rightname, READ);

Html::header(
    PluginGrcmanagerNetworkFlow::getTypeName(2),
    PluginGrcmanagerNetworkFlow::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerNetworkFlow::class
);

PluginGrcmanagerNetworkFlow::showSummary();

$params = QueryBuilder::manageParams(PluginGrcmanagerNetworkFlow::class, $_GET);

Search::showList(
    PluginGrcmanagerNetworkFlow::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerNetworkFlow::class]
);

Html::footer();

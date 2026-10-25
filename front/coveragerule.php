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

// Issue #164 : règles de couverture des preuves.
Session::checkRight(PluginGrcmanagerCoverageRule::$rightname, READ);

Html::header(
    PluginGrcmanagerCoverageRule::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerCoverageRule::class
);

$params = QueryBuilder::manageParams(PluginGrcmanagerCoverageRule::class, $_GET);

Search::showList(
    PluginGrcmanagerCoverageRule::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerCoverageRule::class]
);

Html::footer();

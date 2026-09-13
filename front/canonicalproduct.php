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

Session::checkRight(PluginGrcmanagerCanonicalProduct::$rightname, READ);

Html::header(
    PluginGrcmanagerCanonicalProduct::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerCanonicalProduct::class
);

// Same URL-driven search fix as front/risk.php/front/objective.php, see front/risk.php's own
// docblock for the full rationale.
$params = QueryBuilder::manageParams(PluginGrcmanagerCanonicalProduct::class, $_GET);

Search::showList(
    PluginGrcmanagerCanonicalProduct::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerCanonicalProduct::class]
);

Html::footer();

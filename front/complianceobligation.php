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

Session::checkRight(PluginGrcmanagerComplianceObligation::$rightname, READ);

Html::header(
    PluginGrcmanagerComplianceObligation::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerComplianceObligation::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('complianceobligation');

// Same URL-driven search fix already applied to every list of this plugin (see
// front/risk.php's own docblock for the full explanation): Search::showList() needs $_GET merged
// through QueryBuilder::manageParams() itself, unlike Search::show().
$params = QueryBuilder::manageParams(PluginGrcmanagerComplianceObligation::class, $_GET);

Search::showList(
    PluginGrcmanagerComplianceObligation::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerComplianceObligation::class]
);

Html::footer();

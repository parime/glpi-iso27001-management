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

Session::checkRight(PluginGrcmanagerObjective::$rightname, READ);

Html::header(
    PluginGrcmanagerObjective::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Audit::class,
    PluginGrcmanagerObjective::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Audit::class, PluginGrcmanagerObjective::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('objective');

// Same URL-driven search fix as front/risk.php/front/audit.php (Search::showList()'s $params must
// be pre-merged with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does this
// internally), see front/risk.php's own docblock for the full rationale.
$params = QueryBuilder::manageParams(PluginGrcmanagerObjective::class, $_GET);

Search::showList(
    PluginGrcmanagerObjective::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerObjective::class]
);

Html::footer();

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

Session::checkRight(PluginGrcmanagerPolicy::$rightname, READ);

Html::header(
    PluginGrcmanagerPolicy::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class,
    PluginGrcmanagerPolicy::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerControl::class, PluginGrcmanagerPolicy::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('policy');

// Same $_GET merge as front/risk.php: Search::showList() (unlike Search::show()) doesn't merge
// $_GET itself, needed here too so sort/pagination/filter links on this list actually work, see
// front/risk.php's own docblock for the full explanation.
$params = QueryBuilder::manageParams(PluginGrcmanagerPolicy::class, $_GET);

Search::showList(
    PluginGrcmanagerPolicy::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerPolicy::class]
);

Html::footer();

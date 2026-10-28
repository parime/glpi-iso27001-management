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

// Issue #170 : registre des dérogations de sécurité.
Session::checkRight(\GlpiPlugin\Grcmanager\Waiver::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Waiver::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\Waiver::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\Risk::class, \GlpiPlugin\Grcmanager\Waiver::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('waiver');

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Waiver::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Waiver::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Waiver::class]
);

Html::footer();

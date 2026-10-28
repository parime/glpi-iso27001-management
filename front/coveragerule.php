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
Session::checkRight(\GlpiPlugin\Grcmanager\CoverageRule::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\CoverageRule::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    \GlpiPlugin\Grcmanager\CoverageRule::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Control::class,
    \GlpiPlugin\Grcmanager\CoverageRule::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('coveragerule');

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\CoverageRule::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\CoverageRule::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\CoverageRule::class]
);

Html::footer();

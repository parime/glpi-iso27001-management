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

Session::checkRight(\GlpiPlugin\Grcmanager\Objective::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Objective::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Audit::class,
    \GlpiPlugin\Grcmanager\Objective::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Audit::class, \GlpiPlugin\Grcmanager\Objective::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('objective');

// Same URL-driven search fix as front/risk.php/front/audit.php (Search::showList()'s $params must
// be pre-merged with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does this
// internally), see front/risk.php's own docblock for the full rationale.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Objective::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Objective::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Objective::class]
);

Html::footer();

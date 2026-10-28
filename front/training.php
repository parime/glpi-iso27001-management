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

Session::checkRight(\GlpiPlugin\Grcmanager\Training::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\Training::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\Training::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(\GlpiPlugin\Grcmanager\AccessReview::class, \GlpiPlugin\Grcmanager\Training::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('training');

// Same URL-driven search fix as front/risk.php/front/audit.php (Search::showList()'s $params must
// be pre-merged with $_GET via QueryBuilder::manageParams(), unlike Search::show() which does this
// internally), see front/risk.php's own docblock for the full rationale.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Training::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Training::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Training::class]
);

Html::footer();

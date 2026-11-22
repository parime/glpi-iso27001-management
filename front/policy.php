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

Session::checkRight(\GlpiPlugin\Grcmanager\Policy::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\Policy::class,
    \GlpiPlugin\Grcmanager\Policy::getTypeName(2)
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Policy::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('policy');

// Same $_GET merge as front/risk.php: Search::showList() (unlike Search::show()) doesn't merge
// $_GET itself, needed here too so sort/pagination/filter links on this list actually work, see
// front/risk.php's own docblock for the full explanation.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Policy::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\Policy::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Policy::class]
);

Html::footer();

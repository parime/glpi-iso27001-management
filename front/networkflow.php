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

// Issue #144 : matrice des flux réseau.
Session::checkRight(\GlpiPlugin\Grcmanager\NetworkFlow::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\NetworkFlow::getTypeName(2),
    \GlpiPlugin\Grcmanager\NetworkFlow::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\NetworkFlow::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\NetworkFlow::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('networkflow');

\GlpiPlugin\Grcmanager\NetworkFlow::showSummary();

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\NetworkFlow::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\NetworkFlow::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\NetworkFlow::class]
);

Html::footer();

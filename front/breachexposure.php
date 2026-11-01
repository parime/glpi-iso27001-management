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

// Issue #183 : expositions à des fuites de données (registre sensible).
Session::checkRight(\GlpiPlugin\Grcmanager\BreachExposure::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\BreachExposure::getTypeName(2),
    \GlpiPlugin\Grcmanager\BreachExposure::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\BreachExposure::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\BreachExposure::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('breachexposure');

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\BreachExposure::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\BreachExposure::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\BreachExposure::class]
);

Html::footer();

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

Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceObligation::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\ComplianceObligation::class,
    \GlpiPlugin\Grcmanager\ComplianceObligation::getTypeName(2)
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\ComplianceObligation::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('complianceobligation');

// Same URL-driven search fix already applied to every list of this plugin (see
// front/risk.php's own docblock for the full explanation): Search::showList() needs $_GET merged
// through QueryBuilder::manageParams() itself, unlike Search::show().
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\ComplianceObligation::class, $_GET);

\GlpiPlugin\Grcmanager\Services\Ui\SearchPage::show(
    \GlpiPlugin\Grcmanager\ComplianceObligation::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\ComplianceObligation::class]
);

Html::footer();

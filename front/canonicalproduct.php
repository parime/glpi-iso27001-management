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

Session::checkRight(\GlpiPlugin\Grcmanager\CanonicalProduct::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\CanonicalProduct::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\CanonicalProduct::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\CanonicalProduct::class
);
PluginGrcmanagerPageHelp::show('canonicalproduct');

// Same URL-driven search fix as front/risk.php/front/objective.php, see front/risk.php's own
// docblock for the full rationale.
$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\CanonicalProduct::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\CanonicalProduct::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\CanonicalProduct::class]
);

Html::footer();

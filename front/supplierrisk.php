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

Session::checkRight(PluginGrcmanagerSupplierRisk::$rightname, READ);

Html::header(
    PluginGrcmanagerSupplierRisk::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerSupplierRisk::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('supplierrisk');

// Owner-scoped default view, same shortcut as front/risk.php (search option id 8, see
// PluginGrcmanagerSupplierRisk::rawSearchOptions()).
$myRisksURL = PluginGrcmanagerSupplierRisk::getSearchURL() . '?' . http_build_query([
    'criteria' => [
        ['field' => 8, 'searchtype' => 'equals', 'value' => Session::getLoginUserID()],
    ],
]);
echo '<div class="d-flex justify-content-end mb-2">';
echo '<a href="' . htmlescape($myRisksURL) . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-user-check me-1"></i>' . __('Mes risques fournisseurs', 'grcmanager');
echo '</a></div>';

// Same URL-driven search fix as front/risk.php (Search::showList() does not merge $_GET on its
// own, unlike Search::show()), see its own docblock for the full rationale.
$params = QueryBuilder::manageParams(PluginGrcmanagerSupplierRisk::class, $_GET);

Search::showList(
    PluginGrcmanagerSupplierRisk::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerSupplierRisk::class]
);

Html::footer();

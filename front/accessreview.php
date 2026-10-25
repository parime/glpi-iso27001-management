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

Session::checkRight(PluginGrcmanagerAccessReview::$rightname, READ);

Html::header(
    PluginGrcmanagerAccessReview::getTypeName(2),
    PluginGrcmanagerAccessReview::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerAccessReview::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('accessreview');

echo '<div class="d-flex justify-content-end mb-2">';
echo '<a href="' . htmlescape(PluginGrcmanagerAccessReview::getMineURL())
    . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-list-check me-1"></i>' . __('Mes revues d\'accès', 'grcmanager');
echo '</a></div>';

$params = QueryBuilder::manageParams(PluginGrcmanagerAccessReview::class, $_GET);

Search::showList(
    PluginGrcmanagerAccessReview::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerAccessReview::class]
);

Html::footer();

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

// Issue #142 : liste des événements de conformité saisis.
Session::checkRight(PluginGrcmanagerComplianceEvent::$rightname, READ);

Html::header(
    PluginGrcmanagerComplianceEvent::getTypeName(2),
    PluginGrcmanagerComplianceEvent::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerComplianceEvent::class
);

$calendarUrl = PluginGrcmanagerComplianceEvent::getCalendarURL();
echo '<div class="d-flex justify-content-end mb-2"><a href="' . htmlescape($calendarUrl) . '"'
    . ' class="btn btn-outline-secondary btn-sm"><i class="ti ti-calendar me-1"></i>'
    . htmlescape(__('Calendrier de conformité', 'grcmanager')) . '</a></div>';

$params = QueryBuilder::manageParams(PluginGrcmanagerComplianceEvent::class, $_GET);

Search::showList(
    PluginGrcmanagerComplianceEvent::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerComplianceEvent::class]
);

Html::footer();

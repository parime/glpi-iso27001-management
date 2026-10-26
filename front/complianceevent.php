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

use Glpi\Application\View\TemplateRenderer;
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
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('complianceevent');

$calendarUrl = PluginGrcmanagerComplianceEvent::getCalendarURL();
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => $calendarUrl,
        'icon'  => 'ti-calendar',
        'label' => __('Calendrier de conformité', 'grcmanager'),
    ]],
]);

$params = QueryBuilder::manageParams(PluginGrcmanagerComplianceEvent::class, $_GET);

Search::showList(
    PluginGrcmanagerComplianceEvent::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerComplianceEvent::class]
);

Html::footer();

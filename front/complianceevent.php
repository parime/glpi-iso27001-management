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
Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceEvent::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\ComplianceEvent::getTypeName(2),
    \GlpiPlugin\Grcmanager\ComplianceEvent::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Dashboard::class,
    \GlpiPlugin\Grcmanager\ComplianceEvent::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Dashboard::class,
    \GlpiPlugin\Grcmanager\ComplianceEvent::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('complianceevent');

$calendarUrl = \GlpiPlugin\Grcmanager\ComplianceEvent::getCalendarURL();
TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => [[
        'url'   => $calendarUrl,
        'icon'  => 'ti-calendar',
        'label' => __('Compliance calendar', 'grcmanager'),
    ]],
]);

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\ComplianceEvent::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\ComplianceEvent::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\ComplianceEvent::class]
);

Html::footer();

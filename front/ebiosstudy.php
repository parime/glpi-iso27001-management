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
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

// Issue #376 : études EBIOS Risk Manager.
Session::checkRight(EbiosStudy::$rightname, READ);

Html::header(
    EbiosStudy::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    GlpiPlugin\Grcmanager\Risk::class,
    EbiosStudy::class
);
GlpiPlugin\Grcmanager\Menu::showSection(GlpiPlugin\Grcmanager\Risk::class, EbiosStudy::class);
\GlpiPlugin\Grcmanager\PageHelp::show('ebiosstudy');

$params = QueryBuilder::manageParams(EbiosStudy::class, $_GET);
Search::showList(EbiosStudy::class, $params, DefaultSearchColumns::COLUMNS[EbiosStudy::class]);

Html::footer();

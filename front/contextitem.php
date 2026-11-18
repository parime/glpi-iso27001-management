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
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

// Issue #608 : contexte de l'organisation (enjeux et parties intéressées). Droit général du plugin ;
// la recherche de GLPI limite la liste aux entités de la session.
Session::checkRight(ContextItem::$rightname, READ);

Html::header(
    __('Context of the organization', 'grcmanager'),
    ContextItem::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    ContextItem::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, ContextItem::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('contextitem');
// Question du changement climatique et boutons d'ajout (enjeu, partie intéressée).
ContextItem::showOverview();

$params = QueryBuilder::manageParams(ContextItem::class, $_GET);

Search::showList(ContextItem::class, $params, DefaultSearchColumns::COLUMNS[ContextItem::class]);

Html::footer();

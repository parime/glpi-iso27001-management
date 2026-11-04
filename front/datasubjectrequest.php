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
use GlpiPlugin\Grcmanager\DataSubjectRequest;

include('../../../inc/includes.php');

// Issue #375 : demandes d'exercice des droits (RGPD). Donnée sensible : droit dédié.
Session::checkRight(DataSubjectRequest::$rightname, READ);

Html::header(
    DataSubjectRequest::getTypeName(2),
    DataSubjectRequest::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    DataSubjectRequest::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Control::class, DataSubjectRequest::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('datasubjectrequest');

$params = QueryBuilder::manageParams(DataSubjectRequest::class, $_GET);

Search::showList(DataSubjectRequest::class, $params);

Html::footer();

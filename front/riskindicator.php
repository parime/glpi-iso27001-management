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

// Issue #378 : indicateurs de risque (KRI), registre sensible ; la liste ne montre que les KRI des
// entités de l'utilisateur (moteur de recherche de GLPI).
Session::checkRight(\GlpiPlugin\Grcmanager\RiskIndicator::$rightname, READ);

Html::header(
    \GlpiPlugin\Grcmanager\RiskIndicator::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\RiskIndicator::class
);
// Rubrique « Risques » : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\RiskIndicator::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('riskindicator');

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\RiskIndicator::class, $_GET);
Search::showList(
    \GlpiPlugin\Grcmanager\RiskIndicator::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\RiskIndicator::class]
);

Html::footer();

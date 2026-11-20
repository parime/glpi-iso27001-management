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
use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

// Issue #604 : campagnes de simulation d'hameçonnage. Droit général du plugin ; liste et tendance
// limitées aux entités de la session, taux seulement (le détail par personne est sur la fiche, avec
// le droit des registres sensibles).
Session::checkRight(PhishingCampaign::$rightname, READ);

Html::header(
    PhishingCampaign::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    PhishingCampaign::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\AccessReview::class, PhishingCampaign::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('phishingcampaign');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/phishing_trend.html.twig', [
    'rows' => PhishingCampaign::trendRows(),
]);

$params = QueryBuilder::manageParams(PhishingCampaign::class, $_GET);
Search::showList(PhishingCampaign::class, $params, DefaultSearchColumns::COLUMNS[PhishingCampaign::class]);

Html::footer();

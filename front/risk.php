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

Session::checkRight(PluginGrcmanagerRisk::$rightname, READ);

Html::header(
    PluginGrcmanagerRisk::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerRisk::class
);

// Owner-scoped default view (Sprint 2): a one-click shortcut to the same list pre-filtered on the
// "Propriétaire" search option (id 7, see PluginGrcmanagerRisk::rawSearchOptions()) to the
// logged-in user's own ID. A first version tried GLPI's 'myself' criterion value (used by
// Ticket.php's own default views) but that string is resolved by Ticket/Change/Project's own
// bespoke search logic, NOT generically by the search engine for an arbitrary dropdown-typed
// search option, confirmed live: it fell through to a no-op filter (returned the full unfiltered
// list) rather than an error, so it had to be caught by actually clicking the link against real
// data, not by reading the code alone. Session::getLoginUserID() is resolved here instead, in
// plain PHP, which is what every dropdown-typed search option actually compares against.
$myRisksURL = PluginGrcmanagerRisk::getSearchURL() . '?' . http_build_query([
    'criteria' => [
        ['field' => 7, 'searchtype' => 'equals', 'value' => Session::getLoginUserID()],
    ],
]);
// ROADMAP.md "Version 2.2" (cartographie des risques) : lien vers front/riskheatmap.php, même
// emplacement/style que "Mes risques" ci-dessus - la grille probabilité x impact n'a pas d'entrée
// de menu dédiée (même choix d'empreinte minimale que front/config.php, reachable seulement via
// l'icône de configuration du plugin), ce bouton est donc le seul point d'entrée de ce nouvel
// écran depuis la navigation normale.
$riskHeatmapURL = Plugin::getWebDir('grcmanager') . '/front/riskheatmap.php';
echo '<div class="d-flex justify-content-end mb-2 gap-2">';
echo '<a href="' . htmlescape($riskHeatmapURL) . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-grid-dots me-1"></i>' . __('Cartographie des risques', 'grcmanager');
echo '</a>';
echo '<a href="' . htmlescape($myRisksURL) . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-user-check me-1"></i>' . __('Mes risques', 'grcmanager');
echo '</a></div>';

// Sprint 1 passed an empty array here, which silently discarded any criteria/sort/pagination in
// the URL: Search::showList() -> SearchEngine::showOutput() uses $params exactly as given, unlike
// Search::show() (used by GLPI core's own itemtypes), which internally merges $_GET via
// QueryBuilder::manageParams() before calling the same showOutput(). Confirmed live: without this
// merge, the "Mes risques" link below (and any bookmark/sort/page-2 link) rendered the exact same
// unfiltered first page regardless of its query string. Replicating that same merge here keeps
// showList()'s $forcedisplay argument (the reason Sprint 1 used showList() over show() in the
// first place, see DefaultSearchColumns.php), while restoring URL-driven search behaviour.
$params = QueryBuilder::manageParams(PluginGrcmanagerRisk::class, $_GET);

Search::showList(
    PluginGrcmanagerRisk::class,
    $params,
    DefaultSearchColumns::COLUMNS[PluginGrcmanagerRisk::class]
);

Html::footer();

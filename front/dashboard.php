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
use Glpi\Dashboard\Dashboard;
use Glpi\Dashboard\Grid;

// Issue #247 : tableau de bord du menu GRC & Conformité (même rendu que Parc > Tableau de bord).
Session::checkRight(\GlpiPlugin\Grcmanager\Dashboard::RIGHTNAME, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\Dashboard::class,
    \GlpiPlugin\Grcmanager\Dashboard::getTypeName(),
    \GlpiPlugin\Grcmanager\Dashboard::getSearchURL()
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Dashboard::class);
\GlpiPlugin\Grcmanager\PageHelp::show('dashboard');

// Issue #143 : vue « Incidents de sécurité », proposée seulement à qui peut voir ses cartes.
$views = \GlpiPlugin\Grcmanager\Dashboard::getViews();
$view = is_string($_GET['view'] ?? null) && isset($views[$_GET['view']]) ? $_GET['view'] : 'isms';
TemplateRenderer::getInstance()->display('@grcmanager/dashboard_views.html.twig', [
    'views'  => $views,
    'active' => $view,
    'url'    => \GlpiPlugin\Grcmanager\Dashboard::getSearchURL(),
]);

$key = $views[$view]['key'];
if ($view === 'perimeters') {
    // Issue #536 : consolidé de l'entité active, par entité enfant (entités de la session seules).
    TemplateRenderer::getInstance()->display('@grcmanager/dashboard_perimeters.html.twig', [
        'rows'       => \GlpiPlugin\Grcmanager\EntityPerimeter::consolidation(),
        'can_risks'  => \GlpiPlugin\Grcmanager\Risk::canView(),
        'levels'     => \GlpiPlugin\Grcmanager\Risk::getRiskLevels(),
        'soa_url'    => \GlpiPlugin\Grcmanager\Control::getSearchURL(),
        'risk_url'   => \GlpiPlugin\Grcmanager\Risk::getSearchURL(),
        // Lot 2 : non-conformités ouvertes par entité enfant.
        'can_findings'   => \GlpiPlugin\Grcmanager\Nonconformity::canView(),
        'severities'     => \GlpiPlugin\Grcmanager\Nonconformity::getSeverities(),
        'findings_url'   => \GlpiPlugin\Grcmanager\Nonconformity::getSearchURL(),
        'findings_label' => \GlpiPlugin\Grcmanager\Nonconformity::getTypeName(Session::getPluralNumber()),
    ]);
} elseif ((new Dashboard($key))->canViewCurrent()) {
    // Vue d'ensemble ISMS : 48 rangées, au-delà des 24 par défaut (sans quoi GLPI les superpose).
    (new Grid($key, 26, 48))->showDefault();
} else {
    // Tableau de bord supprimé ou non partagé avec ce profil.
    TemplateRenderer::getInstance()->display('@grcmanager/dashboard_unavailable.html.twig');
}

Html::footer();

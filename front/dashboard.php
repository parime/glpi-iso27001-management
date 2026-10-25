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

include('../../../inc/includes.php');

// Issue #247 : tableau de bord du menu GRC & Conformité (même rendu que Parc > Tableau de bord).
Session::checkRight(PluginGrcmanagerDashboard::RIGHTNAME, READ);

Html::header(
    PluginGrcmanagerDashboard::getTypeName(),
    PluginGrcmanagerDashboard::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerDashboard::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('dashboard');

$key = PluginGrcmanagerDashboard::getDashboardKey();
if ((new Dashboard($key))->canViewCurrent()) {
    (new Grid($key))->showDefault();
} else {
    // Tableau de bord supprimé ou non partagé avec ce profil.
    TemplateRenderer::getInstance()->display('@grcmanager/dashboard_unavailable.html.twig');
}

Html::footer();

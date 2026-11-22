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
use GlpiPlugin\Grcmanager\AssetSecurity;
use GlpiPlugin\Grcmanager\Menu;

// Issue #1039 : page d'accueil de Parc > Sécurité du parc. Visible dès qu'un écran de la rubrique
// l'est ; sinon refus propre (403).
Session::checkLoginUser();
if (!AssetSecurity::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Menu::header(AssetSecurity::class, AssetSecurity::getTypeName(), AssetSecurity::getSearchURL());
Menu::showSection(AssetSecurity::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('assetsecurity');

TemplateRenderer::getInstance()->display('@grcmanager/asset_security_home.html.twig', [
    'groups' => AssetSecurity::homeGroups(),
]);

Html::footer();

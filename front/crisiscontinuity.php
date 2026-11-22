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
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\CrisisContinuity;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\PageHelp;

// Issue #1041 : page d'accueil de « Assistance > Crise et continuité ». Ouverte à qui peut voir au
// moins un écran de la rubrique ; chaque carte n'apparaît qu'avec le droit de son écran.
Session::checkLoginUser();
if (!CrisisContinuity::canView()) {
    throw new AccessDeniedHttpException();
}

Menu::header(CrisisContinuity::class, CrisisContinuity::getTypeName());
Menu::showSection(CrisisContinuity::class);
PageHelp::show('crisiscontinuity');
TemplateRenderer::getInstance()->display('@grcmanager/crisis_continuity.html.twig', [
    'groups' => CrisisContinuity::hubData(date('Y-m-d')),
]);
Html::footer();

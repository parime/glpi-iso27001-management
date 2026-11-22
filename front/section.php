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
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\SectionHome;
use GlpiPlugin\Grcmanager\Navigation\Sections;

// Issue #1038 : page d'accueil d'une rubrique du menu (ce qui est à faire, puis les écrans en cartes
// groupées). Lecture seule. Chaque écran garde son propre contrôle de droits : la page ne montre que
// ceux que le profil peut ouvrir, et refuse (403) une rubrique dont il ne peut rien voir.
Session::checkLoginUser();

$section = Sections::byKey((string) ($_GET['section'] ?? ''));
if ($section === null || !$section::hub()) {
    // Rubrique inconnue (ancien lien, faute de frappe) : le tableau de bord plutôt qu'une page vide.
    Html::redirect(Dashboard::getSearchURL());
}
$data = SectionHome::data($section, (int) Session::getLoginUserID(), date('Y-m-d'));
if ($data === null) {
    throw new AccessDeniedHttpException();
}

Menu::header($section::parent(), $data['title']);
TemplateRenderer::getInstance()->display('@grcmanager/section_home.html.twig', $data);
Html::footer();

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

// Issue #733 : programme des tests de reprise des processus métier, en lecture seule (droit de la
// continuité ; seuls les processus lisibles dans les entités de la session sont listés). Page ouverte
// depuis le bouton de la liste des processus métier, sans entrée de menu.
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, READ);

Html::header(
    __('Recovery test programme', 'grcmanager'),
    \GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\BusinessProcess::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\BusinessProcess::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('recoveryprogramme');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/business_process_programme.html.twig', [
    'programme'     => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::programme(date('Y-m-d')),
    'due_labels'    => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::dueLabels(),
    'result_labels' => \GlpiPlugin\Grcmanager\BusinessProcessRecovery::resultLabels(),
    'list_url'      => \GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL(),
]);
Html::footer();

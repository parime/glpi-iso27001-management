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

// Issue #370 : bibliothèque des procédures de remédiation.
Session::checkRight(\GlpiPlugin\Grcmanager\Playbook::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\Playbook::class,
    \GlpiPlugin\Grcmanager\Playbook::getTypeName(Session::getPluralNumber())
);
\GlpiPlugin\Grcmanager\Menu::showSection(\GlpiPlugin\Grcmanager\Playbook::class);
\GlpiPlugin\Grcmanager\PageHelp::show('playbook');
if (\GlpiPlugin\Grcmanager\Playbook::canCreate()) {
    \Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/playbook_list_header.html.twig', [
        'new_url' => \GlpiPlugin\Grcmanager\Playbook::getFormURL(),
    ]);
}

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\Playbook::class, $_GET);

\GlpiPlugin\Grcmanager\Services\Ui\SearchPage::show(
    \GlpiPlugin\Grcmanager\Playbook::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\Playbook::class]
);

Html::footer();

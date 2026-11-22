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

// Issue #616 : bibliothèque des modèles de messages de crise.
Session::checkRight(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    PluginGrcmanagerSecurityIncident::class,
    \GlpiPlugin\Grcmanager\CrisisMessageTemplate::getTypeName(Session::getPluralNumber())
);
\GlpiPlugin\Grcmanager\PageHelp::show('crisismessagetemplate');
if (\GlpiPlugin\Grcmanager\CrisisMessageTemplate::canCreate()) {
    \Glpi\Application\View\TemplateRenderer::getInstance()->display(
        '@grcmanager/crisis_message_template_list_header.html.twig',
        ['new_url' => \GlpiPlugin\Grcmanager\CrisisMessageTemplate::getFormURL()]
    );
}

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\CrisisMessageTemplate::class, $_GET);

\GlpiPlugin\Grcmanager\Services\Ui\SearchPage::show(
    \GlpiPlugin\Grcmanager\CrisisMessageTemplate::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\CrisisMessageTemplate::class]
);

Html::footer();

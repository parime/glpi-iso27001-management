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
use GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate;

// Issue #864 : bibliothèque des modèles de questionnaire fournisseur. Droit et entités du registre.
// Issue #1040 : onglet de « Gestion > Sécurité des fournisseurs » (fil d'Ariane et barre d'onglets).
Session::checkRight(SupplierQuestionnaireTemplate::$rightname, READ);

\GlpiPlugin\Grcmanager\Menu::header(
    SupplierQuestionnaireTemplate::class,
    SupplierQuestionnaireTemplate::getTypeName(Session::getPluralNumber())
);
\GlpiPlugin\Grcmanager\Menu::showSection(SupplierQuestionnaireTemplate::class);
\GlpiPlugin\Grcmanager\PageHelp::show('supplierquestionnairetemplate');
$buttons = [[
    'url'   => \GlpiPlugin\Grcmanager\SupplierRisk::getSearchURL(),
    'icon'  => 'ti-truck',
    'label' => __('Back to supplier risks', 'grcmanager'),
]];
if (SupplierQuestionnaireTemplate::canCreate()) {
    $buttons[] = [
        'url'   => SupplierQuestionnaireTemplate::getFormURL(),
        'icon'  => 'ti-plus',
        'label' => __('New questionnaire template', 'grcmanager'),
    ];
}
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/_list_toolbar.html.twig', [
    'buttons' => $buttons,
]);

$params = QueryBuilder::manageParams(SupplierQuestionnaireTemplate::class, $_GET);

Search::showList(
    SupplierQuestionnaireTemplate::class,
    $params,
    DefaultSearchColumns::COLUMNS[SupplierQuestionnaireTemplate::class]
);

Html::footer();

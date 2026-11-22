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
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\PolicyTemplate;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyTemplateCatalog as Catalog;

// Issue #545 : catalogue de modèles de politiques prêts à adapter. Importer crée une nouvelle
// politique en brouillon par modèle coché, jamais une politique approuvée ni remplacée.
Session::checkRight(PolicyTemplate::RIGHTNAME, READ);
if (!PolicyTemplate::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['import'])) {
    if (!PolicyTemplate::canImport()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $language = PolicyTemplate::language((string) ($_POST['language'] ?? ''));
    $result = PolicyTemplate::import(
        is_array($_POST['keys'] ?? null) ? $_POST['keys'] : [],
        $language,
        [
            'organisation'  => (string) ($_POST['organisation'] ?? ''),
            'ciso_users_id' => (int) ($_POST['ciso_users_id'] ?? 0),
            'review_months' => $_POST['review_months'] ?? null,
        ]
    );
    $count = count($result['created']);
    if ($count === 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Tick at least one template.', 'grcmanager')), false, WARNING);
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            _n(
                '%1$d draft policy created, with %2$d links to the Statement of Applicability. '
                    . 'Adapt it, then have it approved.',
                '%1$d draft policies created, with %2$d links to the Statement of Applicability. '
                    . 'Adapt them, then have them approved.',
                $count,
                'grcmanager'
            ),
            $count,
            $result['links']
        )));
    }
    if ($result['unknown'] !== []) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Codes not found in the Statement of Applicability: %s', 'grcmanager'),
            implode(', ', $result['unknown'])
        )), false, WARNING);
    }
    Html::redirect(PolicyTemplate::getSearchURL() . '?language=' . rawurlencode($language));
}

$language = PolicyTemplate::language(isset($_GET['language']) ? (string) $_GET['language'] : null);

Html::header(
    PolicyTemplate::getTypeName(Session::getPluralNumber()),
    PolicyTemplate::getSearchURL(),
    'grcmanager',
    Control::class,
    PolicyTemplate::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, PolicyTemplate::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('policytemplate');
TemplateRenderer::getInstance()->display('@grcmanager/policy_templates.html.twig', [
    'action'         => PolicyTemplate::getSearchURL(),
    'policies_url'   => Policy::getSearchURL(),
    'policy_url'     => Policy::getFormURL(),
    'coverage_url'   => PolicyCoverage::canView() ? PolicyCoverage::getSearchURL() : '',
    'can_import'     => PolicyTemplate::canImport(),
    'language'       => $language,
    'languages'      => ['fr' => 'Français', 'en' => 'English'],
    'license'        => Catalog::LICENSE,
    'templates'      => Catalog::templates(Catalog::load($language)),
    'imported'       => PolicyTemplate::importedPolicies(),
    'control_titles' => Control::getControlTitles(),
    'organisation'   => PolicyTemplate::defaultOrganisation(),
    'ciso_users_id'  => (int) Session::getLoginUserID(),
    'review_months'  => Catalog::REVIEW_MONTHS_DEFAULT,
    'review_min'     => Catalog::REVIEW_MONTHS_MIN,
    'review_max'     => Catalog::REVIEW_MONTHS_MAX,
]);
Html::footer();

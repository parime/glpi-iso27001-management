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
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;

include('../../../inc/includes.php');

// Issue #178 : catalogue de risques types d'infrastructure.
Session::checkRight(\GlpiPlugin\Grcmanager\Risk::$rightname, READ);

if (isset($_POST['import'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Risk::$rightname, CREATE);
    $created = \GlpiPlugin\Grcmanager\RiskCatalog::import((array) ($_POST['keys'] ?? []));
    if ($created === []) {
        $message = __('Tick at least one risk that is not in the register yet.', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, WARNING);
    } else {
        $message = sprintf(
            _n(
                '%d risk added to the register, to be assessed.',
                '%d risks added to the register, to be assessed.',
                count($created),
                'grcmanager'
            ),
            count($created)
        );
        Session::addMessageAfterRedirect(htmlescape($message));
    }
    Html::redirect(\GlpiPlugin\Grcmanager\RiskCatalog::getSearchURL());
}

Html::header(
    \GlpiPlugin\Grcmanager\RiskCatalog::getTypeName(),
    \GlpiPlugin\Grcmanager\Risk::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('riskcatalog');

$controlIds = [];
foreach ((new \GlpiPlugin\Grcmanager\Control())->find() as $control) {
    $controlIds[(string) $control['code']] = (int) $control['id'];
}
$controls = [];
foreach (InfrastructureRiskCatalog::keys() as $key) {
    foreach (InfrastructureRiskCatalog::controls($key) as $code) {
        $controls[$key][$code] = isset($controlIds[$code])
            ? \GlpiPlugin\Grcmanager\Control::getFormURLWithID($controlIds[$code])
            : '';
    }
}
$iso27090Enabled = FrameworkConfig::isEnabled(FrameworkSelection::ISO27090);

TemplateRenderer::getInstance()->display('@grcmanager/risk_catalog.html.twig', [
    'action'     => \GlpiPlugin\Grcmanager\RiskCatalog::getSearchURL(),
    'risks_url'  => \GlpiPlugin\Grcmanager\Risk::getSearchURL(),
    'entries'    => \GlpiPlugin\Grcmanager\RiskCatalog::getEntries(),
    'categories' => array_combine(
        InfrastructureRiskCatalog::keys(),
        array_map([InfrastructureRiskCatalog::class, 'category'], InfrastructureRiskCatalog::keys())
    ),
    'labels'     => \GlpiPlugin\Grcmanager\Risk::getCategories(),
    // Infrastructure puis systèmes d'IA (sections ISO/IEC 27090:2026).
    'groups'     => [
        InfrastructureRiskCatalog::GROUP_INFRASTRUCTURE => __('Infrastructure', 'grcmanager'),
        InfrastructureRiskCatalog::GROUP_AI             => $iso27090Enabled
            ? __('AI systems (ISO/IEC 27090)', 'grcmanager')
            : __('AI systems', 'grcmanager'),
    ],
    'group_of'   => array_combine(
        InfrastructureRiskCatalog::keys(),
        array_map([InfrastructureRiskCatalog::class, 'group'], InfrastructureRiskCatalog::keys())
    ),
    // Issue #403 : sans ISO/IEC 27090, ni badges ni lien vers ce référentiel.
    'iso27090'   => array_combine(
        InfrastructureRiskCatalog::keys(),
        array_map(
            static fn (string $key): array => $iso27090Enabled ? InfrastructureRiskCatalog::iso27090($key) : [],
            InfrastructureRiskCatalog::keys()
        )
    ),
    'iso27090_enabled' => $iso27090Enabled,    'controls'   => $controls,
    'imported'   => \GlpiPlugin\Grcmanager\RiskCatalog::importedRisks(),
    'risk_url'   => \GlpiPlugin\Grcmanager\Risk::getFormURL(),
    'can_create' => Session::haveRight(\GlpiPlugin\Grcmanager\Risk::$rightname, CREATE),
]);

Html::footer();

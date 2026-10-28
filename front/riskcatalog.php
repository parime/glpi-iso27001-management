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
use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;

include('../../../inc/includes.php');

// Issue #178 : catalogue de risques types d'infrastructure.
Session::checkRight(PluginGrcmanagerRisk::$rightname, READ);

if (isset($_POST['import'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, CREATE);
    $created = PluginGrcmanagerRiskCatalog::import((array) ($_POST['keys'] ?? []));
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
    Html::redirect(PluginGrcmanagerRiskCatalog::getSearchURL());
}

Html::header(
    PluginGrcmanagerRiskCatalog::getTypeName(),
    PluginGrcmanagerRisk::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerRisk::class
);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('riskcatalog');

$controlIds = [];
foreach ((new PluginGrcmanagerControl())->find() as $control) {
    $controlIds[(string) $control['code']] = (int) $control['id'];
}
$controls = [];
foreach (InfrastructureRiskCatalog::keys() as $key) {
    foreach (InfrastructureRiskCatalog::controls($key) as $code) {
        $controls[$key][$code] = isset($controlIds[$code])
            ? PluginGrcmanagerControl::getFormURLWithID($controlIds[$code])
            : '';
    }
}

TemplateRenderer::getInstance()->display('@grcmanager/risk_catalog.html.twig', [
    'action'     => PluginGrcmanagerRiskCatalog::getSearchURL(),
    'risks_url'  => PluginGrcmanagerRisk::getSearchURL(),
    'entries'    => PluginGrcmanagerRiskCatalog::getEntries(),
    'categories' => array_combine(
        InfrastructureRiskCatalog::keys(),
        array_map([InfrastructureRiskCatalog::class, 'category'], InfrastructureRiskCatalog::keys())
    ),
    'labels'     => PluginGrcmanagerRisk::getCategories(),
    'controls'   => $controls,
    'imported'   => PluginGrcmanagerRiskCatalog::importedRisks(),
    'risk_url'   => PluginGrcmanagerRisk::getFormURL(),
    'can_create' => Session::haveRight(PluginGrcmanagerRisk::$rightname, CREATE),
]);

Html::footer();

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

include('../../../inc/includes.php');

// Issue #177 : points de défaillance uniques, et création du risque correspondant à la demande.
Session::checkRight(PluginGrcmanagerImpactAnalysis::RIGHTNAME, READ);

if (isset($_POST['create_risk'])) {
    Session::checkRight(PluginGrcmanagerRisk::$rightname, CREATE);
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $item = in_array($itemtype, PluginGrcmanagerRisk::getLinkableItemtypes(), true) ? new $itemtype() : null;
    if ($item === null || !$item->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$item->canViewItem()) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    $analysis = PluginGrcmanagerImpactAnalysis::analyze($item);
    $riskId = PluginGrcmanagerImpactAnalysis::createSinglePointRisk($item, $analysis['spof_for']);
    if ($riskId > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Risque créé dans le registre, à évaluer.', 'grcmanager')));
        Html::redirect(PluginGrcmanagerRisk::getFormURLWithID($riskId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('Ce risque existe déjà pour cet élément.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
}

Html::header(
    PluginGrcmanagerImpactAnalysis::getTypeName(),
    PluginGrcmanagerImpactAnalysis::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerImpactAnalysis::class
);

TemplateRenderer::getInstance()->display('@grcmanager/impact_spof.html.twig', [
    'points'     => PluginGrcmanagerImpactAnalysis::allSinglePoints(),
    'action'     => PluginGrcmanagerImpactAnalysis::getSearchURL(),
    'can_create' => Session::haveRight(PluginGrcmanagerRisk::$rightname, CREATE),
]);

Html::footer();

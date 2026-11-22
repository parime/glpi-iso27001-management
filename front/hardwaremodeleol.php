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
use GlpiPlugin\Grcmanager\HardwareModelEol;
use GlpiPlugin\Grcmanager\HardwareWarranty;
use GlpiPlugin\Grcmanager\Menu;

// Issue #1039 : fin de vie constructeur des modèles de matériel, saisie groupée (Parc > Sécurité du
// parc) ; l'onglet « Fin de vie constructeur » des fiches de modèles envoie aussi ici. Lecture avec le
// droit du plugin, saisie avec le droit de le modifier (comme l'ancien bloc de la configuration).
Session::checkRight(HardwareModelEol::RIGHTNAME, READ);

if (isset($_POST['update_hardware_eol'])) {
    Session::checkRight(HardwareModelEol::RIGHTNAME, UPDATE);
    HardwareModelEol::handleSave($_POST);
    // Depuis l'onglet d'un modèle : retour sur sa fiche ; sinon sur la saisie groupée.
    if (!empty($_POST['from_model'])) {
        Html::back();
    }
    Html::redirect(HardwareModelEol::getSearchURL());
}

Menu::header(HardwareModelEol::class, HardwareModelEol::getTypeName(), HardwareModelEol::getSearchURL());
Menu::showSection(HardwareModelEol::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('hardwaremodeleol');

TemplateRenderer::getInstance()->display('@grcmanager/hardware_model_eol.html.twig', [
    'hardware_eol' => HardwareWarranty::modelConfigData(),
    'action'       => HardwareModelEol::getSearchURL(),
    'csrf_token'   => Session::getNewCSRFToken(),
    'can_edit'     => HardwareModelEol::canEdit(),
    'warning_days' => (int) \GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore::settings()['warning_days'],
    'park_url'     => \GlpiPlugin\Grcmanager\ParkVulnerability::getSearchURL() . '#grc-hardware-warranty',
]);

Html::footer();

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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\RecyfRequirement;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults;

global $CFG_GLPI;

// Issue #599 : registre ReCyF (NIS2 France) de l'entité active (ou d'une entité de la session qui a
// une catégorie), avec sa synthèse et ses exports. Pas d'entrée de menu : ouvert depuis la page
// Référentiels, la configuration et l'évaluation par référentiel. Droit général du plugin,
// référentiel NIS2 actif.
Session::checkRight(RecyfRequirement::$rightname, READ);
if (!RecyfRequirement::isFrameworkEnabled()) {
    throw new AccessDeniedHttpException();
}

$registers = RecyfRequirement::availableRegisters();
$entityId = RecyfRequirement::selectedRegister($registers, $_GET['entities_id'] ?? null);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\Referentials::class,
    RecyfRequirement::getTypeName(Session::getPluralNumber()),
    RecyfRequirement::getSearchURL()
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/recyf_register.html.twig', [
    'registers'        => $registers,
    'entity_id'        => $entityId,
    'dashboard'        => $entityId !== null ? RecyfRequirement::dashboard($entityId) : null,
    'categories'       => RecyfRequirement::getCategories(),
    'register_url'     => RecyfRequirement::getSearchURL(),
    'export_url'       => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/recyfrequirement.export.php',
    'config_url'       => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grcmanager-recyf',
    'can_configure'    => (bool) Session::haveRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE),
    'referentials_url' => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php',
    'assessment_url'   => \GlpiPlugin\Grcmanager\FrameworkAssessment::getSearchURL()
        . '?framework=' . \GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection::NIS2,
    'version'          => RecyfCatalogDefaults::VERSION,
    'source_page'      => RecyfCatalogDefaults::SOURCE_PAGE,
    'source_document'  => RecyfCatalogDefaults::SOURCE_DOCUMENT,
]);

Html::footer();

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
use GlpiPlugin\Grcmanager\AcnRequirement;

global $CFG_GLPI;

// Issue #703 : registre NIS2 Italia - ACN de l'entité active (ou d'une entité de la session qui a
// une catégorie), avec sa synthèse et ses exports. Pas d'entrée de menu : ouvert depuis la page
// Référentiels et la configuration. Droit général du plugin, référentiel actif.
Session::checkRight(AcnRequirement::$rightname, READ);
if (!AcnRequirement::isFrameworkEnabled()) {
    throw new AccessDeniedHttpException();
}

$registers = AcnRequirement::availableRegisters();
$entityId = AcnRequirement::selectedRegister($registers, $_GET['entities_id'] ?? null);

\GlpiPlugin\Grcmanager\Menu::header(
    \GlpiPlugin\Grcmanager\Referentials::class,
    AcnRequirement::getTypeName(Session::getPluralNumber()),
    AcnRequirement::getSearchURL()
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/acn_register.html.twig', [
    'registers'      => $registers,
    'entity_id'      => $entityId,
    'dashboard'      => $entityId !== null ? AcnRequirement::dashboard($entityId) : null,
    'categories'     => AcnRequirement::getCategories(),
    'register_url'   => AcnRequirement::getSearchURL(),
    'export_url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/acnrequirement.export.php',
    'config_url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grcmanager-acn',
    'can_configure'  => Session::haveRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE),
    'referentials_url' => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php',
    'determination'  => \GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults::DETERMINATION,
    'source_annex_1' => \GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults::SOURCE_ANNEX_1,
    'source_annex_2' => \GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults::SOURCE_ANNEX_2,
]);

Html::footer();

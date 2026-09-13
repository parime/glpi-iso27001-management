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

use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;

include('../../../inc/includes.php');

// Reuses PluginGrcmanagerControl's own right rather than a dedicated one: this screen only
// displays the same Annexe A catalog (plus two purely-consultative reference libraries), no
// meaningful access boundary of its own — same reasoning as PluginGrcmanagerSecurityIncidentCve
// reusing PluginGrcmanagerSecurityIncident's right.
Session::checkRight(PluginGrcmanagerControl::$rightname, READ);

Html::header(
    __('Référentiels', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    PluginGrcmanagerControl::class
);

$titles = PluginGrcmanagerControl::getControlTitles();
$themes = PluginGrcmanagerControl::getThemes();

global $DB;
$idsByCode = [];
foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => PluginGrcmanagerControl::getTable()]) as $row) {
    $idsByCode[$row['code']] = (int) $row['id'];
}

$annexA = [];
foreach (ControlCatalogDefaults::CONTROLS as $code => $theme) {
    $bareCode = substr($code, 2); // 'A.5.1' -> '5.1'
    $annexA[] = [
        'code'      => $code,
        'id'        => $idsByCode[$code] ?? null,
        'theme'     => $themes[$theme] ?? $theme,
        'title'     => $titles[$code] ?? $code,
        'crosswalk' => ControlCrosswalkDefaults::CROSSWALK[$bareCode]['nist_csf'] ?? [],
    ];
}

$nistCsf = [];
foreach (NistCsfCatalogDefaults::FUNCTIONS as $functionCode => $functionName) {
    $categories = [];
    foreach (NistCsfCatalogDefaults::CATEGORIES as $categoryCode => $category) {
        if ($category['function'] !== $functionCode) {
            continue;
        }
        $subcategories = [];
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $subCode => $subcategory) {
            if ($subcategory['category'] === $categoryCode) {
                $subcategories[] = ['code' => $subCode, 'text' => $subcategory['text']];
            }
        }
        $categories[] = [
            'code'          => $categoryCode,
            'name'          => $category['name'],
            'subcategories' => $subcategories,
        ];
    }
    $nistCsf[] = ['code' => $functionCode, 'name' => $functionName, 'categories' => $categories];
}

$cisControls = [];
foreach (CisControlsCatalogDefaults::CONTROLS as $controlNumber => $controlName) {
    $safeguards = [];
    foreach (CisControlsCatalogDefaults::SAFEGUARDS as $safeguardCode => $safeguard) {
        if ($safeguard['control'] === $controlNumber) {
            $safeguards[] = ['code' => $safeguardCode, 'text' => $safeguard['text'], 'ig' => $safeguard['ig']];
        }
    }
    $cisControls[] = ['number' => $controlNumber, 'name' => $controlName, 'safeguards' => $safeguards];
}

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/referentiels.html.twig', [
    'annex_a'      => $annexA,
    'nist_csf'     => $nistCsf,
    'cis_controls' => $cisControls,
]);

Html::footer();

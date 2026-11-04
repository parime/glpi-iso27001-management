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
use GlpiPlugin\Grcmanager\Services\Risk\RiskAppetiteRules;

include('../../../inc/includes.php');

// Issue #378 : appétence et tolérance au risque par catégorie, et risques qui les dépassent.
// Registre sensible : lecture avec son droit, modification avec UPDATE.
Session::checkRight(\GlpiPlugin\Grcmanager\RiskAppetite::RIGHTNAME, READ);

if (isset($_POST['save_appetite'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\RiskAppetite::RIGHTNAME, UPDATE);
    $posted = $_POST['appetite'] ?? [];
    $rejected = \GlpiPlugin\Grcmanager\RiskAppetite::save(is_array($posted) ? $posted : []);
    if ($rejected !== []) {
        Session::addMessageAfterRedirect(
            htmlescape(__(
                'The tolerance cannot be below the appetite: these categories were not saved.',
                'grcmanager'
            )),
            false,
            ERROR
        );
    }
    Html::back();
}

Html::header(
    \GlpiPlugin\Grcmanager\RiskAppetite::getTypeName(),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\RiskAppetite::class
);
// Rubrique « Risques » : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Risk::class,
    \GlpiPlugin\Grcmanager\RiskAppetite::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('riskappetite');

$settings = \GlpiPlugin\Grcmanager\RiskAppetite::all();
$categories = [];
foreach (\GlpiPlugin\Grcmanager\Risk::getCategories() as $key => $label) {
    $row = $settings[$key] ?? null;
    $categories[] = [
        'key'       => $key,
        'label'     => $label,
        'appetite'  => (string) ($row['appetite_level'] ?? ''),
        'tolerance' => (string) ($row['tolerance_level'] ?? ''),
        'statement' => (string) ($row['statement'] ?? ''),
        'approver'  => $row === null ? '' : getUserName((int) $row['users_id_approval']),
        'approved'  => (string) ($row['date_approval'] ?? ''),
    ];
}
$risks = [];
foreach (\GlpiPlugin\Grcmanager\RiskAppetite::risksAboveAppetite() as $risk) {
    $acceptedBy = (int) ($risk['users_id_acceptance'] ?? 0);
    $risks[] = [
        'title'           => (string) $risk['title'],
        'url'             => \GlpiPlugin\Grcmanager\Risk::getFormURLWithID((int) $risk['id']),
        'category'        => \GlpiPlugin\Grcmanager\Risk::getCategories()[$risk['category']] ?? '',
        'level_badge'     => \GlpiPlugin\Grcmanager\RiskAppetite::levelBadgeData((string) $risk['risk_level']),
        'treatment'       => \GlpiPlugin\Grcmanager\Risk::getTreatments()[$risk['treatment']] ?? '',
        'above_tolerance' => $risk['position'] === RiskAppetiteRules::ABOVE_TOLERANCE,
        'needs_decision'  => $risk['needs_decision'],
        'accepted_by'     => $acceptedBy > 0 ? getUserName($acceptedBy) : '',
    ];
}

TemplateRenderer::getInstance()->display('@grcmanager/risk_appetite.html.twig', [
    'categories' => $categories,
    'levels'     => ['' => Dropdown::EMPTY_VALUE] + \GlpiPlugin\Grcmanager\Risk::getImpacts(),
    'can_update' => \GlpiPlugin\Grcmanager\RiskAppetite::canUpdate(),
    'action'     => \GlpiPlugin\Grcmanager\RiskAppetite::getSearchURL(),
    'risks'      => $risks,
    'kri_url'    => \GlpiPlugin\Grcmanager\RiskIndicator::getSearchURL(),
]);

Html::footer();

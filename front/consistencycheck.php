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
use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;

// Issue #614 : contrôles de cohérence du SMSI avant un audit. Lecture seule : rien n'est corrigé,
// chaque écart renvoie à la fiche à corriger. Seul le résumé du mois est enregistré (tendance).
global $CFG_GLPI;

Session::checkRight(ConsistencyCheck::RIGHTNAME, READ);

$filters = ConsistencyRules::filters($_GET);
$results = ConsistencyCheck::run();
$report = ConsistencyCheck::report($filters, $results);
ConsistencyCheck::snapshot($report['summary']);
$texts = ConsistencyCheck::getRuleTexts();
$ruleOptions = [];
foreach (ConsistencyRules::RULES as $key => $rule) {
    if ($results[$key]['enabled'] && $results[$key]['evaluated']) {
        $ruleOptions[$key] = $texts[$key]['label'];
    }
}

\GlpiPlugin\Grcmanager\Menu::header(
    ConsistencyCheck::class,
    ConsistencyCheck::getTypeName(),
    ConsistencyCheck::getSearchURL()
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(ConsistencyCheck::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('consistencycheck');
TemplateRenderer::getInstance()->display('@grcmanager/consistency_check.html.twig', $report + [
    'action'       => ConsistencyCheck::getSearchURL(),
    'area_labels'  => ConsistencyCheck::getAreaLabels(),
    'rule_options' => $ruleOptions,
    'history'      => ConsistencyCheck::history(),
    'csv_url'      => ConsistencyCheck::getExportURL('csv', $filters),
    'pdf_url'      => ConsistencyCheck::getExportURL('pdf', $filters),
    'config_url'   => Session::haveRight(Installer::RIGHT_NAME, UPDATE)
        ? $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-consistency-rules'
        : '',
    'max_gaps'     => ConsistencyCheck::PAGE_GAPS_MAX,
    'filtered'     => implode('', $filters) !== '',
]);
Html::footer();

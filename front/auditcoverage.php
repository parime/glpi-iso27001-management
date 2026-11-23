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
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditChecklist;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules;

/**
 * Issue #606 : couverture du programme d'audit (ISO 27001, clause 9.2). Pour chaque exigence du
 * SMSI et chaque mesure applicable de la SoA de l'entité active : date et résultat du dernier audit
 * d'après les grilles d'audit, sur une période paramétrable ; celles jamais auditées en tête.
 * Lecture seule ; audits des entités de la session.
 */
Session::checkRight(Audit::$rightname, READ);

$years = ChecklistRules::coverageYears($_GET['years'] ?? ChecklistRules::DEFAULT_COVERAGE_YEARS);

\GlpiPlugin\Grcmanager\Menu::header(Audit::class, __('Audit programme coverage', 'grcmanager'));
\GlpiPlugin\Grcmanager\Menu::showSection(Audit::class);

$yearChoices = [];
for ($i = 1; $i <= ChecklistRules::MAX_COVERAGE_YEARS; $i++) {
    $yearChoices[$i] = sprintf(_n('Last %d year', 'Last %d years', $i, 'grcmanager'), $i);
}

TemplateRenderer::getInstance()->display('@grcmanager/audit_coverage.html.twig', [
    'coverage'     => AuditChecklist::coverage($years),
    'year_choices' => $yearChoices,
    'audits_url'   => Audit::getSearchURL(),
]);

Html::footer();

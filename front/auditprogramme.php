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
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules;
use GlpiPlugin\Grcmanager\Training;

/**
 * Issue #773 : programme d'audit interne pluriannuel (ISO 27001 9.2, ISO 19011). Matrice des
 * processus du SMSI par année du cycle de certification, couverture et alertes. Lecture avec le
 * droit de lire les audits ; les réglages (début du cycle, formations qualifiantes des
 * auditeurs) demandent le droit de les modifier. Audits et processus des entités de la session.
 */
Session::checkRight(Audit::$rightname, READ);

if (isset($_POST['save_settings'])) {
    Session::checkRight(Audit::$rightname, UPDATE);
    AuditProgramme::setCycleStart($_POST['cycle_start'] ?? null);
    AuditProgramme::setQualifyingTrainingIds((array) ($_POST['training_ids'] ?? []));
    Session::addMessageAfterRedirect(__('Audit programme settings saved.', 'grcmanager'));
    Html::redirect(AuditProgramme::getSearchURL());
}

$start = AuditProgramme::cycleStart($_GET['start'] ?? null);

Html::header(AuditProgramme::getTypeName(), $_SERVER['PHP_SELF'], 'grcmanager', Audit::class);
\GlpiPlugin\Grcmanager\Menu::showSection(Audit::class, AuditProgramme::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('auditprogramme');

$currentYear = (int) date('Y');
$startChoices = [];
$lastYear = $currentYear + ProgrammeRules::MAX_FUTURE_YEARS;
for ($year = $currentYear - ProgrammeRules::MAX_PAST_YEARS; $year <= $lastYear; $year++) {
    $startChoices[$year] = sprintf('%d - %d', $year, $year + ProgrammeRules::CYCLE_LENGTH - 1);
}
$trainings = Training::getMainTrainings();
unset($trainings[0]);

TemplateRenderer::getInstance()->display('@grcmanager/audit_programme.html.twig', [
    'programme'     => AuditProgramme::programme($start),
    'start_choices' => $startChoices,
    'can_edit'      => Audit::canUpdate(),
    'trainings'     => $trainings,
    'qualifying'    => AuditProgramme::qualifyingTrainingIds(),
    'audits_url'    => Audit::getSearchURL(),
    'new_audit_url' => Audit::canCreate() ? Audit::getFormURL() : '',
    'coverage_url'  => AuditChecklist::getCoverageURL(),
    'raci_url'      => ResponsibilityActivity::canView() ? ResponsibilityActivity::getSearchURL() : '',
    'action'        => AuditProgramme::getSearchURL(),
    'check_labels'  => AuditProgramme::checkLabels(),
    'check_states'  => AuditProgramme::checkStates(),
]);

Html::footer();

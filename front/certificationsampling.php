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
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationSampling;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules;

/**
 * Issue #772, lot 2 : plan d'audit de l'organisme de certification. Matrice processus x années du
 * cycle, cases cochées rattachées à l'audit de certification de chaque année, et processus
 * échantillonnés de l'audit de l'année. Lecture avec le droit de lire les audits ; cocher demande
 * de pouvoir modifier chaque audit concerné (droit et entité, check() du cœur). Jeton CSRF vérifié
 * par GLPI sur chaque POST.
 */
Session::checkRight(CertificationSampling::RIGHTNAME, READ);

if (isset($_POST['save_sampling'])) {
    Session::checkRight(CertificationSampling::RIGHTNAME, UPDATE);
    $count = CertificationSampling::saveMatrix(
        (array) ($_POST['audit_ids'] ?? []),
        (array) ($_POST['sampled'] ?? [])
    );
    if ($count > 0) {
        Session::addMessageAfterRedirect(__('Audit plan of the certification body saved.', 'grcmanager'));
    }
    $start = AuditProgramme::cycleStart($_POST['start'] ?? null);
    Html::redirect(CertificationSampling::getSearchURL() . '?start=' . $start);
}

if (isset($_POST['save_audit_sampling'])) {
    // Onglet « Plan d'audit de l'organisme » d'un audit de certification.
    Session::checkRight(CertificationSampling::RIGHTNAME, UPDATE);
    $audit = CertificationSampling::editableAudit((int) ($_POST['plugin_grcmanager_audits_id'] ?? 0));
    AuditProgramme::setProcesses($audit, (array) ($_POST['processes'] ?? []));
    Html::back();
}

$start = AuditProgramme::cycleStart($_GET['start'] ?? null);

\GlpiPlugin\Grcmanager\Menu::header(
    CertificationSampling::class,
    CertificationSampling::getTypeName(),
    CertificationSampling::getSearchURL()
);
\GlpiPlugin\Grcmanager\Menu::showSection(CertificationSampling::class);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('certificationsampling');

$currentYear = (int) date('Y');
$startChoices = [];
$lastYear = $currentYear + ProgrammeRules::MAX_FUTURE_YEARS;
for ($year = $currentYear - ProgrammeRules::MAX_PAST_YEARS; $year <= $lastYear; $year++) {
    $startChoices[$year] = sprintf('%d - %d', $year, $year + ProgrammeRules::CYCLE_LENGTH - 1);
}

$plan = CertificationSampling::plan($start);
$yearAudit = null;
$yearProcesses = [];
if ($plan['year_audit'] > 0) {
    $yearAudit = new Audit();
    if ($yearAudit->getFromDB($plan['year_audit']) && $yearAudit->can($plan['year_audit'], READ)) {
        $yearProcesses = CertificationSampling::sampledProcesses($yearAudit);
    } else {
        $yearAudit = null;
    }
}

TemplateRenderer::getInstance()->display('@grcmanager/certification_sampling.html.twig', [
    'plan'           => $plan,
    'start_choices'  => $startChoices,
    'action'         => CertificationSampling::getSearchURL(),
    'new_audit_url'  => Audit::canCreate() ? Audit::getFormURL() : '',
    'raci_url'       => ResponsibilityActivity::canView() ? ResponsibilityActivity::getSearchURL() : '',
    'findings_url'   => CertificationFinding::canView() ? CertificationFinding::getSearchURL() : '',
    'programme_url'  => AuditProgramme::getSearchURL(),
    'year_audit'     => $yearAudit,
    'year_processes' => $yearProcesses,
    'lift_states'    => CertificationFinding::getLiftStates(),
]);

Html::footer();

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
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;

include('../../../inc/includes.php');

// Issue #162 : audit de conformité technique (score par référentiel, écarts, tickets).
Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceAudit::RIGHTNAME, READ);

$approvals = (int) ($_POST['approvals'] ?? $_GET['approvals'] ?? 1) === 2 ? 2 : 1;

// Issue #305 : statuts d'ordinateur exclus des contrôles (réformé, en stock…).
if (isset($_POST['update_excluded_states'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceAudit::RIGHTNAME, UPDATE);
    $states = $_POST['excluded_states'] ?? [];
    \GlpiPlugin\Grcmanager\ComplianceAudit::setExcludedStateIds(is_array($states) ? $states : []);
    Html::back();
}

if (isset($_POST['create_ticket'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\ComplianceAudit::RIGHTNAME, UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    $ticketId = \GlpiPlugin\Grcmanager\ComplianceAudit::createTicket((string) $_POST['create_ticket'], $approvals);
    if ($ticketId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('No gap to handle: the ticket was not created.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
}

$excludedStates = \GlpiPlugin\Grcmanager\ComplianceAudit::getExcludedStateIds();
$results = \GlpiPlugin\Grcmanager\ComplianceAudit::run($approvals);
$labels = \GlpiPlugin\Grcmanager\ComplianceAudit::getCheckLabels($approvals);
$frameworks = \GlpiPlugin\Grcmanager\ComplianceAudit::getFrameworkLabels();
$checks = [];
foreach ($results as $key => $result) {
    $references = [];
    foreach (ComplianceAuditScore::CHECKS[$key] as $framework => $reference) {
        $references[] = $frameworks[$framework] . ' ' . $reference;
    }
    $ticket = $key === 'accounts' ? null : \GlpiPlugin\Grcmanager\ComplianceAudit::openTicket($key);
    $checks[] = $labels[$key] + [
        'key'        => $key,
        'evaluated'  => $result['evaluated'],
        'total'      => $result['total'],
        'gap_count'  => count($result['gaps']),
        'gaps'       => array_slice($result['gaps'], 0, 20),
        'rate'       => ComplianceAuditScore::rate(count($result['gaps']), $result['total']),
        'references' => $references,
        'ticket_url' => $ticket === null ? '' : Ticket::getFormURLWithID($ticket->getID()),
        'ticket_id'  => $ticket === null ? 0 : $ticket->getID(),
    ];
}
$scores = [];
foreach (
    ComplianceAuditScore::frameworkScores(array_map(
        static fn (array $result): array => [
            'gaps'      => count($result['gaps']),
            'total'     => $result['total'],
            'evaluated' => $result['evaluated'],
        ],
        $results
    )) as $framework => $score
) {
    $scores[] = $score + ['label' => $frameworks[$framework]];
}

Html::header(
    \GlpiPlugin\Grcmanager\ComplianceAudit::getTypeName(),
    \GlpiPlugin\Grcmanager\ComplianceAudit::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\ComplianceAudit::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\ComplianceAudit::class,
    \GlpiPlugin\Grcmanager\ComplianceAudit::class
);
// Encadré « À quoi sert cette page ? ».
\GlpiPlugin\Grcmanager\PageHelp::show('complianceaudit');
TemplateRenderer::getInstance()->display('@grcmanager/compliance_audit.html.twig', [
    'scores'       => $scores,
    'checks'       => $checks,
    'approvals'    => $approvals,
    'action'       => \GlpiPlugin\Grcmanager\ComplianceAudit::getSearchURL(),
    'can_ticket'   => Session::haveRight(\GlpiPlugin\Grcmanager\ComplianceAudit::RIGHTNAME, UPDATE)
        && Session::haveRight(Ticket::$rightname, CREATE),
    'can_update'      => Session::haveRight(\GlpiPlugin\Grcmanager\ComplianceAudit::RIGHTNAME, UPDATE),
    'excluded_states' => $excludedStates,
    'excluded_names'  => array_map(
        static fn (int $id): string => Dropdown::getDropdownName(State::getTable(), $id),
        $excludedStates
    ),
    'hygiene_url'  => \GlpiPlugin\Grcmanager\AccountHygiene::getSearchURL(),
    'maturity_url' => \GlpiPlugin\Grcmanager\Maturity::getSearchURL(),
    'soa_url'      => \GlpiPlugin\Grcmanager\Control::getSearchURL(),
]);
Html::footer();

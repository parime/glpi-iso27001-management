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
Session::checkRight(PluginGrcmanagerComplianceAudit::RIGHTNAME, READ);

$approvals = (int) ($_POST['approvals'] ?? $_GET['approvals'] ?? 1) === 2 ? 2 : 1;

if (isset($_POST['create_ticket'])) {
    Session::checkRight(PluginGrcmanagerComplianceAudit::RIGHTNAME, UPDATE);
    Session::checkRight(Ticket::$rightname, CREATE);
    $ticketId = PluginGrcmanagerComplianceAudit::createTicket((string) $_POST['create_ticket'], $approvals);
    if ($ticketId > 0) {
        Html::redirect(Ticket::getFormURLWithID($ticketId));
    }
    Session::addMessageAfterRedirect(
        htmlescape(__('Aucun écart à traiter : le ticket n\'a pas été créé.', 'grcmanager')),
        false,
        WARNING
    );
    Html::back();
}

$results = PluginGrcmanagerComplianceAudit::run($approvals);
$labels = PluginGrcmanagerComplianceAudit::getCheckLabels($approvals);
$frameworks = PluginGrcmanagerComplianceAudit::getFrameworkLabels();
$checks = [];
foreach ($results as $key => $result) {
    $references = [];
    foreach (ComplianceAuditScore::CHECKS[$key] as $framework => $reference) {
        $references[] = $frameworks[$framework] . ' ' . $reference;
    }
    $ticket = $key === 'accounts' ? null : PluginGrcmanagerComplianceAudit::openTicket($key);
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
    PluginGrcmanagerComplianceAudit::getTypeName(),
    PluginGrcmanagerComplianceAudit::getSearchURL(),
    'grcmanager',
    PluginGrcmanagerComplianceAudit::class
);
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
PluginGrcmanagerMenu::showSection(PluginGrcmanagerComplianceAudit::class, PluginGrcmanagerComplianceAudit::class);
// Encadré « À quoi sert cette page ? ».
PluginGrcmanagerPageHelp::show('complianceaudit');
TemplateRenderer::getInstance()->display('@grcmanager/compliance_audit.html.twig', [
    'scores'       => $scores,
    'checks'       => $checks,
    'approvals'    => $approvals,
    'action'       => PluginGrcmanagerComplianceAudit::getSearchURL(),
    'can_ticket'   => Session::haveRight(PluginGrcmanagerComplianceAudit::RIGHTNAME, UPDATE)
        && Session::haveRight(Ticket::$rightname, CREATE),
    'hygiene_url'  => PluginGrcmanagerAccountHygiene::getSearchURL(),
    'maturity_url' => PluginGrcmanagerMaturity::getSearchURL(),
    'soa_url'      => PluginGrcmanagerControl::getSearchURL(),
]);
Html::footer();

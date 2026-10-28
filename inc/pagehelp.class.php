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

/**
 * Encadré « À quoi sert cette page ? » en tête de chaque écran du menu GRC & Conformité : ce
 * qu'on y trouve, ce qui est attendu de l'utilisateur, et la référence de la norme. Repliable ;
 * le choix est retenu par le navigateur, page par page.
 */
class PluginGrcmanagerPageHelp
{
    /**
     * @return array<string, array{purpose: string, expected: string, reference?: string}>
     */
    public static function texts(): array
    {
        return [
            'dashboard' => [
                'purpose'  => __(
                    'The information security management system dashboard: risks, controls, incidents, deadlines '
                        . 'and reviews at a glance.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Click a card to open the matching list. The dashboard menu lets you add or move cards, and '
                        . 'share it with other profiles.',
                    'grcmanager'
                ),
            ],
            'maturity' => [
                'purpose'  => __(
                    'The cyber maturity score (0 to 100): an overview of the progress of your security management '
                        . 'system, computed from your data.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Present it in management reviews; the calculation details show which components to improve '
                        . 'first.',
                    'grcmanager'
                ),
            ],
            'complianceaudit' => [
                'purpose'   => __(
                    'Automated technical checks: checks computed from GLPI data (antivirus, disk encryption, '
                        . 'inventory, change validation, accounts), with a score per framework.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Review the gaps of each check and create the remediation ticket; once the items are fixed, the '
                        . 'score goes up. Present the scores at the management review.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.1; NIS2, article 21; GDPR, article 32', 'grcmanager'),
            ],
            'crisisexercise' => [
                'purpose'   => __(
                    'Crisis exercises: simulate an incident (ransomware, data leak, outage) to check that the team '
                        . 'knows how to react and that the continuity plan works.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Start a scenario: a fictitious "EXERCISE" incident is created with one task per step. In the '
                        . 'crisis room, tick each step as soon as it is done; at the end, the report measures reaction '
                        . 'times and serves as audit evidence. At least one exercise per year.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.29 and 5.30', 'grcmanager'),
            ],
            'risk' => [
                'purpose'   => __(
                    'The information security risk register: each risk is assessed (likelihood × impact), linked '
                        . 'to the assets it threatens and to the controls that reduce it.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Identify risks, assess them, decide on a treatment (reduce, accept, transfer, avoid) and set a '
                        . 'review date. The owner is notified when it is due.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.2 and 6.1.3; 8.2 and 8.3', 'grcmanager'),
            ],
            'riskheatmap' => [
                'purpose'  => __(
                    'The risk heat map: how risks are spread by likelihood and impact, to see at a glance where '
                        . 'high risks are concentrated.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Use it in management reviews; click a cell to open the matching risks.',
                    'grcmanager'
                ),
            ],
            'riskcatalog' => [
                'purpose'  => __(
                    'Typical infrastructure risks (exposed administration service, flat network, poorly isolated '
                        . 'guest Wi-Fi...), ready to be added to the register.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Tick those that apply to you and import them, then assess them in the register. Nothing is '
                        . 'imported without your choice.',
                    'grcmanager'
                ),
            ],
            'supplierrisk' => [
                'purpose'   => __(
                    'Risks related to your suppliers and service providers: dependency, access to your data, '
                        . 'security of their services.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Create one risk per important supplier, have the security questionnaire filled in, follow its '
                        . 'contracts and the "Security watch" tab.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.19 to 5.22', 'grcmanager'),
            ],
            'control' => [
                'purpose'   => __(
                    'The Statement of Applicability (SoA): the 93 Annex A controls, each declared applicable or '
                        . 'not, with its justification and implementation status.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each control, state whether it applies, why, and how far its implementation has '
                        . 'progressed. Then have the SoA approved.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.3 d)', 'grcmanager'),
            ],
            'soaapproval' => [
                'purpose'  => __(
                    'Approved versions of the Statement of Applicability, with the approver and the date.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Request approval after every significant change to the SoA; the approver cannot be the '
                        . 'requester.',
                    'grcmanager'
                ),
            ],
            'ismsrequirement' => [
                'purpose'   => __(
                    'The requirements of clauses 4 to 10 of the standard (context, leadership, planning, support, '
                        . 'operation, evaluation, improvement) and the evidence that they are met.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each requirement, state its status and attach or link the evidence (document, review, '
                        . 'procedure).',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, clauses 4 to 10', 'grcmanager'),
            ],
            'complianceobligation' => [
                'purpose'   => __(
                    'Your legal, regulatory and contractual security obligations (GDPR, NIS2, customer clauses...).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'List them with their source and owner, and set a review date to check they are still met.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 4.2; Annex A 5.31', 'grcmanager'),
            ],
            'audit' => [
                'purpose'   => __(
                    'The internal audit programme: scope, dates, auditors, report and findings.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Plan the year\'s audits, enter the report and the findings; each nonconformity opens an action '
                        . 'plan.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.2', 'grcmanager'),
            ],
            'nonconformity' => [
                'purpose'   => __(
                    'Nonconformities and observations, with their corrective and preventive actions (CAPA).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Analyse the cause, describe the corrective action, assign an owner and a due date, then check '
                        . 'its effectiveness before closing.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 10.2', 'grcmanager'),
            ],
            'training' => [
                'purpose'   => __(
                    'Security training and awareness sessions, with their participants and results.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record each session, its participants and its renewal date; a reminder is sent when it is due.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 7.2 and 7.3', 'grcmanager'),
            ],
            'managementreview' => [
                'purpose'   => __(
                    'Management reviews: the periodic review of the information security management system by top '
                        . 'management.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'At least once a year, prepare the review (indicators, audits, incidents, risks), record the '
                        . 'decisions and the resulting actions.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.3', 'grcmanager'),
            ],
            'objective' => [
                'purpose'   => __('Security objectives and their indicators measured over time.', 'grcmanager'),
                'expected'  => __(
                    'Define measurable objectives, enter the measurements each period and track progress towards '
                        . 'the target.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.2 and 9.1', 'grcmanager'),
            ],
            'accessreview' => [
                'purpose'   => __(
                    'Access rights review campaigns: each manager confirms or removes the rights of their team.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Start a campaign; managers decide line by line. Removing a right opens a ticket, it is never '
                        . 'applied automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.18', 'grcmanager'),
            ],
            'accounthygiene' => [
                'purpose'  => __(
                    'Accounts to check: inactive accounts, accounts missing from the directory, duplicates or never '
                        . 'used.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Review the list and open a ticket for each account to disable; nothing is disabled '
                        . 'automatically.',
                    'grcmanager'
                ),
            ],
            'periodicreview' => [
                'purpose'  => __(
                    'Periodic reviews (backups, logs, rights, continuity plan...) scheduled as recurring tickets.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Enable the useful reviews and their frequency; a ticket is created at each due date for the '
                        . 'person in charge.',
                    'grcmanager'
                ),
            ],
            'networkflow' => [
                'purpose'   => __(
                    'The matrix of authorised network flows: who talks to whom, on which port, and why.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Describe each flow with its justification and owner, or import your firewall export, then '
                        . 'review the matrix regularly.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 8.20 to 8.22', 'grcmanager'),
            ],
            'compliancecalendar' => [
                'purpose'  => __(
                    'The compliance calendar: all due dates (reviews, audits, renewals, obligations) in one place, '
                        . 'also visible in the GLPI planning.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Check it to anticipate due dates; subscribe your calendar with the iCal link.',
                    'grcmanager'
                ),
            ],
            'complianceevent' => [
                'purpose'  => __(
                    'One-off compliance events to add to the calendar (regulator inspection, crisis exercise, '
                        . 'contractual deadline...).',
                    'grcmanager'
                ),
                'expected' => __(
                    'Add the event with its date and owner; it appears in the compliance calendar.',
                    'grcmanager'
                ),
            ],
            'policy' => [
                'purpose'   => __(
                    'The library of security policies, versioned, with their approval and review date.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Upload each policy, have it approved, distribute it and review it when due.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 5.2; Annex A 5.1', 'grcmanager'),
            ],
            'evidence' => [
                'purpose'  => __(
                    'Dated evidence (reports, attestations, certificates) and its validity.',
                    'grcmanager'
                ),
                'expected' => __(
                    'State how long each piece of evidence is valid; a renewal ticket is opened when it expires.',
                    'grcmanager'
                ),
            ],
            'coveragerule' => [
                'purpose'  => __(
                    'Coverage rules: which assets must have which document (for example, every server must have its '
                        . 'operations sheet).',
                    'grcmanager'
                ),
                'expected' => __(
                    'Create one rule per requirement; the result lists the covered assets and those to complete.',
                    'grcmanager'
                ),
            ],
            'waiver' => [
                'purpose'   => __(
                    'Security exemptions: accepted exceptions to a policy or a control, limited in time and '
                        . 'compensated.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Justify each exemption, describe the compensating measures, have it approved and set its end '
                        . 'date; it is reassessed when due.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.3; Annex A 5.36', 'grcmanager'),
            ],
            'impactanalysis' => [
                'purpose'   => __(
                    'Single points of failure: critical items that depend on a single source, according to GLPI\'s '
                        . 'impact analysis.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Create the matching risk or add redundancy; export the list for your business continuity plan.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.29, 5.30 and 8.14', 'grcmanager'),
            ],
            'causalgraph' => [
                'purpose'  => __('The domino effect: how one incident or risk can lead to others.', 'grcmanager'),
                'expected' => __(
                    'Link items from the "Domino effect" tab of a risk or an incident; this page shows the whole '
                        . 'picture.',
                    'grcmanager'
                ),
            ],
            'securityincident' => [
                'purpose'   => __(
                    'Security incidents: qualification, affected assets, CVEs, costs, regulatory notifications '
                        . '(data protection authority, NIS2).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Declare the incident, qualify it, follow up the actions and meet the notification deadlines '
                        . 'shown on the record.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.24 to 5.28', 'grcmanager'),
            ],
            'parkvulnerability' => [
                'purpose'  => __(
                    'Known vulnerabilities (CVEs) of the software installed on your fleet, with their real-world '
                        . 'exploitation (KEV, EPSS) and CERT-FR advisories.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Handle exploited and priority vulnerabilities first; open one update ticket per software.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 8.8', 'grcmanager'),
            ],
            'softwarewatch' => [
                'purpose'  => __(
                    'Fleet software to match with a CPE reference so that its vulnerabilities are tracked.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Start with the most exposed (browsers, remote access, VPN): search for the CPE, confirm it, or '
                        . 'ignore software with no stakes.',
                    'grcmanager'
                ),
            ],
            'parkmachines' => [
                'purpose'  => __(
                    'The machines most exposed to the known vulnerabilities of their watched software, to know '
                        . 'where to start updating.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Handle the machines at the top of the list first; the "Vulnerabilities" tab of each one '
                        . 'details the CVEs and the update ticket.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 8.8', 'grcmanager'),
            ],
            'canonicalproduct' => [
                'purpose'  => __(
                    'The correlation catalogue: reference products, their CPEs and the names under which they '
                        . 'appear in the inventory.',
                    'grcmanager'
                ),
                'expected' => __(
                    'It is filled from "Software to watch"; correct an alias or a CPE here if needed.',
                    'grcmanager'
                ),
            ],
            'referentiels' => [
                'purpose'  => __(
                    'Mappings between Annex A and other frameworks, to reuse your work across several standards.',
                    'grcmanager'
                ),
                'expected' => __('Check the mappings before an audit against another framework.', 'grcmanager'),
            ],
        ];
    }

    public static function show(string $page): void
    {
        $text = self::texts()[$page] ?? null;
        if ($text === null) {
            return;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/_page_help.html.twig', ['page' => $page] + $text);
    }
}

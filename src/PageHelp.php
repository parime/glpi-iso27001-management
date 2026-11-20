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

namespace GlpiPlugin\Grcmanager;

use Glpi\Application\View\TemplateRenderer;

/**
 * Encadré « À quoi sert cette page ? » en tête de chaque écran du menu GRC & Conformité : ce
 * qu'on y trouve, ce qui est attendu de l'utilisateur, et la référence de la norme. Repliable ;
 * le choix est retenu par le navigateur, page par page.
 */
class PageHelp
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
            // Issue #867 : mesures bloquées de la SoA.
            'controlblocker'   => [
                'purpose'  => __(
                    'For each control of the Statement of Applicability, this page lists what prevents it from '
                        . 'being compliant: failing automated check, missing or expired evidence, policy not approved, '
                        . 'effectiveness test overdue or ineffective, open nonconformity, expired waiver.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Sort by severity and start with the most blocked controls. Click a control to see its blockers, '
                        . 'then click a blocker to open the screen where it is fixed. The page is computed again each '
                        . 'time and only shows what you are allowed to read. Nothing is changed automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001:2022 clause 9.1 and control A.5.36.', 'grcmanager'),
            ],
            // Issue #614 : contrôles de cohérence du SMSI avant un audit.
            'consistencycheck' => [
                'purpose'  => __(
                    'Before an audit, this page gathers the inconsistencies of your security management system: '
                        . 'risks without an owner, controls without evidence, expired evidence, policies not reviewed, '
                        . 'nonconformities closed without action, and more.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Start with the high severity gaps. Click an item to open it, fix it, then come back: the page is '
                        . 'computed again each time. Nothing is changed automatically. Export the list (CSV or PDF) to '
                        . 'follow the preparation of the audit.',
                    'grcmanager'
                ),
            ],
            // Issue #772 : constats des audits de certification précédents.
            'certificationfinding' => [
                'purpose'  => __(
                    'At each surveillance audit, the certification body checks that the findings of its previous '
                        . 'audit have been dealt with. This page lists those findings (nonconformities, areas of '
                        . 'concern, opportunities for improvement) with their corrective action, their evidence and '
                        . 'whether they are lifted.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Record each certification audit with its type, then its findings in the Findings tab of the '
                        . 'audit, with the reference given by the body. Treat each finding (cause, action, owner, due '
                        . 'date, evidence in the Documents tab) until it is verified. Export the PDF and hand it to '
                        . 'the auditor at the opening meeting of the next audit.',
                    'grcmanager'
                ),
            ],
            // Issue #772, lot 2 : plan d'audit de l'organisme de certification.
            'certificationsampling' => [
                'purpose'  => __(
                    'The certification body samples the processes of your security management system over its '
                        . 'three-year cycle: it does not audit them all every year. This page copies its sampling '
                        . 'programme (processes in rows, years in columns) and, for the audit of the year, lists '
                        . 'the sampled processes with their previous findings and the screens to prepare.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Record each certification audit of the cycle with its planned date. Then tick, in the column '
                        . 'of each audit, the processes announced by the audit plan of the body, and save. Before '
                        . 'the audit, go through each sampled process: lift its previous findings and check the '
                        . 'controls and registers it relies on. The processes are those of the internal audit '
                        . 'programme (ISMS activities of the responsibility matrix).',
                    'grcmanager'
                ),
                'reference' => __(
                    'ISO/IEC 27006-1 (certification audit programme), ISO/IEC 27001 clause 9.2',
                    'grcmanager'
                ),
            ],
            // Issue #619 : préparation à la certification ISO 27001.
            'certificationreadiness' => [
                'purpose'  => __(
                    'This page guides you, step by step, from setting up your security management system to '
                        . 'the certification audit: for each step, it shows what is expected, where you stand '
                        . 'according to the data already entered, and the screen to open to complete it.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Follow the steps in order, starting with the one marked as the next step. Click its button to '
                        . 'open the screen concerned, complete it, then come back: the page is computed again each '
                        . 'time. Nothing is changed automatically. Give the target date of the audit (or plan the '
                        . 'certification audit): each step not done gets an advised deadline, shown in the '
                        . 'compliance calendar, and the owner is warned when one risks being late. Export the PDF '
                        . 'readiness report to share where you stand.',
                    'grcmanager'
                ),
            ],
            'myspace' => [
                'purpose'  => __(
                    'Everything waiting for you in the security management system, on one page and sorted by due date.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Start with the overdue items highlighted in red, then open each line to act on it. The number '
                        . 'next to the menu entry counts these items.',
                    'grcmanager'
                ),
            ],
            // Issue #538 : pages ouvertes sans droit sur le plugin.
            'auditevidencerequest.mine' => [
                'purpose'  => __(
                    'The documents an audit is waiting for from you: each request says what is expected, for '
                        . 'which control and by when.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Add the files (or an existing evidence of the control) to each request, then submit it to '
                        . 'the audit. If the audit asks you to complete a request, its reason is shown in red.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 9.2 (internal audit)', 'grcmanager'),
            ],
            'auditorspace' => [
                'purpose'  => __(
                    'The audits you have been given access to, in read only: controls, progress, submitted '
                        . 'evidence and findings.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Open an audit, then download the submitted documents. Every download is recorded. Your '
                        . 'access ends on the date set by the organisation.',
                    'grcmanager'
                ),
            ],
            // Issue #540 : registre d'informations DORA des prestataires TIC.
            'doraregister' => [
                'purpose'   => __(
                    'The register of all the contracts with ICT third-party service providers that DORA requires: '
                        . 'contracts, providers and their subcontractors, functions supported and their criticality, '
                        . 'data locations, substitutability and exit plans. It is built from GLPI contracts, '
                        . 'suppliers and business processes.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Fill in the financial entity below, then add each ICT contract from its "DORA register" tab and '
                        . 'complete the missing fields listed here. When nothing is missing, download the templates '
                        . 'and give them to your reporting tool.',
                    'grcmanager'
                ),
                'reference' => __('DORA, article 28(3); Implementing Regulation (EU) 2024/2956', 'grcmanager'),
            ],
            'frameworkassessment' => [
                'purpose'   => __(
                    'Assessment by framework: for each enabled framework, how many of its controls are implemented, '
                        . 'how many are proven by valid evidence, and the resulting score.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Open the gaps of a framework, then update each control or attach the missing evidence; an '
                        . 'expired document no longer counts. Present the scores at the management review.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.1 and 9.3; mapping of each framework to Annex A', 'grcmanager'),
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
            // Issue #602 : import et export CSV du registre des risques.
            'riskimport' => [
                'purpose'   => __(
                    'Bring an existing risk register kept in a spreadsheet into the plugin, and take it back out '
                        . 'in the same format.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Download the template or export the register, fill it in, then analyse the file: nothing is '
                        . 'saved before you have read the report and confirmed. Importing the same file again updates '
                        . 'the risks without duplicates.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.2 and 8.2', 'grcmanager'),
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
            'riskappetite' => [
                'purpose'   => __(
                    'The risk appetite and tolerance set by management for each risk category, and the open risks '
                        . 'that exceed them.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Have management approve the levels; then give each risk above the appetite a treatment or a '
                        . 'formal, justified acceptance. Risks above tolerance come first.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.2; ISO 31000, 6.3.4', 'grcmanager'),
            ],
            'riskindicator' => [
                'purpose'   => __(
                    'Key risk indicators (KRI): values measured over time, entered by hand or measured every day '
                        . 'from technical checks, fleet vulnerabilities and the risk register, with orange and red '
                        . 'thresholds.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Set the thresholds of each indicator, enter the manual values at each review, and open a ticket '
                        . 'for any indicator in the red.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.2 and 9.1', 'grcmanager'),
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
            // Issue #611 : approbation des politiques de sécurité par la direction.
            'policyapproval' => [
                'purpose'   => __(
                    'The security policies awaiting your approval: read the frozen version, then approve or '
                        . 'reject it with a comment.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Answer each request; a rejection needs a comment so that the author can correct the policy.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, A.5.1', 'grcmanager'),
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
            'processingactivity' => [
                'purpose'   => __(
                    'The record of processing activities required by the GDPR: one entry per processing of '
                        . 'personal data, with its purpose, legal basis, data, recipients and retention periods.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Describe each processing, link the applications, assets and suppliers involved, settle the '
                        . 'gaps shown, review the register every year and export it if the supervisory authority '
                        . 'asks for it.',
                    'grcmanager'
                ),
                'reference' => __('GDPR, article 30; ISO 27001, Annex A 5.34', 'grcmanager'),
            ],
            'datasubjectrequest' => [
                'purpose'   => __(
                    'Requests from people exercising their GDPR rights (access, rectification, erasure...) and the '
                        . 'deadline to answer each one: one month, or three when the extension is justified.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record each request on the day it arrives, answer before the deadline and attach the proof. '
                        . 'Put the identity of the person only in the Requester field.',
                    'grcmanager'
                ),
                'reference' => __('GDPR, articles 12 and 15 to 22', 'grcmanager'),
            ],
            // Issue #779 : conformité des licences logicielles.
            'softwarelicensecompliance' => [
                'purpose'   => __(
                    'Checks, from the software and licenses already recorded in GLPI, that the organization '
                        . 'respects the licenses it bought: commercial software installed without any license, '
                        . 'more installations than licensed seats, and expired licenses still in use. Results '
                        . 'are grouped by entity.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Check the list of watched publishers, then for each finding record the missing license in '
                        . 'GLPI, buy seats, uninstall the software or renew the license. Export the list (CSV) for '
                        . 'the auditor. Nothing is changed automatically.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.5.32', 'grcmanager'),
            ],
            'contextitem' => [
                'purpose'   => __(
                    'The context of the organization: the internal and external issues that affect information '
                        . 'security, and the interested parties with their expectations and what the ISMS retains.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'List the issues and the interested parties, decide for each one whether the ISMS retains it, '
                        . 'link the related risks, obligations and controls, answer the climate change question, and '
                        . 'review each item at least once a year. Use the export of the list for the auditor.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clauses 4.1 and 4.2', 'grcmanager'),
            ],
            // Issue #778 : sécurité physique des sites (comparaison et liste des évaluations).
            'physicalsecurity' => [
                'purpose'   => __(
                    'Shows, for each site (a location of GLPI), the last physical security assessment: the state of '
                        . 'each control A.7.1 to A.7.14 side by side, the score, and when the site must be assessed '
                        . 'again (at least once a year).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Assess each site: walk through it, record the state, the finding and the evidence (photos, '
                        . 'reports) of each control, and list its zones with their access controls. Create an action '
                        . 'for each partial or non-compliant control. Link the physical security policy. Assess the '
                        . 'site again before the date shown.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.7.1 to A.7.14', 'grcmanager'),
            ],
            // Issue #775 : registre des procédures d'exploitation.
            'operatingprocedure' => [
                'purpose'   => __(
                    'Shows whether each main IT operations activity (backup, restore, monitoring, updates...) has a '
                        . 'documented procedure that is approved and reviewed on time, with its version, its owner and '
                        . 'who has acknowledged it.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each domain without an up-to-date procedure, add one or update it: attach the document, have '
                        . 'it approved, set its next review date, then launch the reading acknowledgement for the '
                        . 'groups or profiles that carry it out. Archive the register on control A.5.37 before an '
                        . 'audit.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.5.37', 'grcmanager'),
            ],
            // Issue #777 : développement sécurisé.
            'securedevelopment' => [
                'purpose'   => __(
                    'The applications developed or maintained in-house, with their secure development practices '
                        . '(framework, code review, SAST, DAST and dependency analyses, separated environments), and '
                        . 'the releases of the last 12 months with their security validation.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Fill in the "Secure development" tab of each application, link the changes to their '
                        . 'application, then have each release approved by the CIO or the CISO in the "Security '
                        . 'release validation" tab of the change, before it goes into production.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.25 to A.8.31', 'grcmanager'),
            ],
            // Issue #771 : registre des contacts officiels.
            'officialcontact' => [
                'purpose'   => __(
                    'Who to contact outside the organization when something happens: authorities (data protection '
                        . 'authority, national CSIRT, police), regulators, insurers and special interest groups, with '
                        . 'their details, when to contact them, the time limit and who is allowed to call them.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record each official contact, tick the events for which it must be informed, verify the '
                        . 'details at least once a year, and keep a printed copy of the register (PDF) for use '
                        . 'during a crisis.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.5.5 and A.5.6', 'grcmanager'),
            ],
            'responsibilityactivity' => [
                'purpose'   => __(
                    'Who performs, approves, is consulted on or is informed of each activity of the ISMS, with the '
                        . 'GLPI users and groups. Conflicts of duties and missing approvers are reported here.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each activity, add who performs it and a single approver, mark the sensitive activities, '
                        . 'fix the alerts, then archive the matrix as evidence before the audit.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 5.3 and control A.5.3', 'grcmanager'),
            ],
            // Issue #774 : compétences requises par fonction, acquises et écarts.
            'ismsfunction' => [
                'purpose'   => __(
                    'The competences each ISMS function needs (CISO, DPO, administrator...), who holds each function, '
                        . 'what each person has acquired through passed trainings or declared evidence, and the gaps.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Add the functions and their required competences with the expected level, say who holds them, '
                        . 'link the trainings that give each competence, declare the certificates and diplomas, plan '
                        . 'the proposed actions, then archive the file as evidence before the audit.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 7.2', 'grcmanager'),
            ],
            'competence' => [
                'purpose'   => __(
                    'The catalogue of the information security competences of the organization, and the trainings '
                        . 'that give each one once passed.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Describe each competence and link the trainings that give it, with the level reached.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 7.2', 'grcmanager'),
            ],
            'competencedeclaration' => [
                'purpose'   => __(
                    'The competences acquired outside the trainings of the plugin, each one with its evidence: '
                        . 'certificate, diploma, annual review or proven experience.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record the reference of the evidence, its date and its end of validity, and attach the file in '
                        . 'the Documents tab.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 7.2', 'grcmanager'),
            ],
            'databreach' => [
                'purpose'   => __(
                    'The register of personal data breaches: every breach is documented, whether or not it was '
                        . 'notified, with its facts, effects and the measures taken.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record each breach as soon as it is known (from the security incident when there is one), '
                        . 'decide within 72 h whether to notify the supervisory authority and write down why, decide '
                        . 'whether to inform the people concerned, then export the register when the authority asks '
                        . 'for it.',
                    'grcmanager'
                ),
                'reference' => __('GDPR, articles 33 and 34', 'grcmanager'),
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
            // Issue #773 : programme d'audit interne pluriannuel.
            'auditprogramme' => [
                'purpose'   => __(
                    'The internal audit programme over the certification cycle: which ISMS process is audited which '
                        . 'year, and which one is not covered yet.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Make sure every process is audited at least once over the cycle: plan an audit for each '
                        . 'process in red or orange, and choose its processes in the "Programme and independence" '
                        . 'tab of the audit.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.2.2; ISO 19011, 5', 'grcmanager'),
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
            // Issue #604 : simulations d'hameçonnage, preuve de sensibilisation.
            'phishingcampaign' => [
                'purpose'   => __(
                    'Phishing simulations: the fake phishing campaigns sent with an external tool (GoPhish, a '
                        . 'provider...), their results and the trend from one campaign to the next. The plugin '
                        . 'never sends any email.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Record each campaign (date, tool, scenario, targeted population), then import the results '
                        . 'file of the tool in the Results tab. Offer a remedial training to the people who '
                        . 'clicked. Inform the staff beforehand that simulations take place, and delete the '
                        . 'results per person once the follow-up is done.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 7.3 and control A.6.3', 'grcmanager'),
            ],
            'phishingimport' => [
                'purpose'   => __(
                    'Import of the results of a phishing simulation: each line of the file is matched with a '
                        . 'GLPI user by email address or login.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Check the column of each field, then import. The lines not matched are listed on the '
                        . 'campaign so that you can correct them.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, clause 7.3 and control A.6.3', 'grcmanager'),
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
            'personnelcompliance' => [
                'purpose'   => __(
                    'Security compliance of each person: screening, confidentiality agreement, mandatory trainings, '
                        . 'IT charter, security policies read, access review and account hygiene.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Follow up the gaps with the person and their manager; nothing is changed automatically. '
                        . 'The deadline for newcomers and the weight of each component are set in the plugin '
                        . 'configuration.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 6.1, 6.2, 6.3, 6.5, 6.6 and 5.18', 'grcmanager'),
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
            'breachexposure' => [
                'purpose'   => __(
                    'Work email addresses found in known data breaches (Have I Been Pwned), checked every day.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each new exposure, have the password changed (and enable multi-factor authentication) '
                        . 'if passwords were exposed, then mark it as handled.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annex A 5.7 and 5.17', 'grcmanager'),
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
            'controltest' => [
                'purpose'   => __(
                    'The effectiveness tests of the Annex A controls: a control in place is checked to really work, '
                        . 'with its method, result and evidence.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Set a test plan on each control, record each test from the control\'s "Effectiveness tests" '
                        . 'tab and carry out the tests that are due first. An ineffective control opens a '
                        . 'non-conformity.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.1; ISO 27002, 5.35 and 5.36', 'grcmanager'),
            ],
            'auditlog' => [
                'purpose'  => __(
                    'The audit log of the security incidents: who consulted or changed what, when, with which '
                        . 'profile and from which address, including impersonations.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Filter the entries, export them for an audit or a court, and check the integrity regularly: '
                        . 'each entry is sealed with the previous one.',
                    'grcmanager'
                ),
            ],
            'securityincidentedrimport' => [
                'purpose'  => __(
                    'Import of a CSV export of the alerts from an EDR or XDR console: each alert becomes an incident '
                        . 'with its indicators, MITRE ATT&CK techniques, hosts and accounts.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Check the columns proposed, choose how to group the alerts, then import. An alert already '
                        . 'imported is ignored; the original lines are kept unchanged on the incident.',
                    'grcmanager'
                ),
            ],
            'ebiosstudy' => [
                'purpose'  => __(
                    'EBIOS Risk Manager studies: the risk assessment method of ANSSI, the French national '
                        . 'cybersecurity agency, published under an open licence.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Create one study per scope, then work through its workshops in order: business values and '
                        . 'feared events, risk sources, stakeholders and strategic scenarios, operational scenarios, '
                        . 'then their treatment, which feeds the risk register.',
                    'grcmanager'
                ),
            ],
            'playbook' => [
                'purpose'  => __(
                    'Remediation playbooks: the standard steps to follow for each type of incident (ransomware, '
                        . 'phishing, compromised account...).',
                    'grcmanager'
                ),
                'expected' => __(
                    'Adapt the five playbooks provided to your context; from an incident, apply one to get its plan of '
                        . 'tasks. Mandatory steps must be done or marked not applicable before closing.',
                    'grcmanager'
                ),
            ],
            // Issue #776 : scénarios de crise sécurité de l'information du PCA et leur couverture.
            'crisisscenario' => [
                'purpose'   => __(
                    'Information security crisis scenarios of the continuity plan: ransomware, data leak, '
                        . 'compromise of privileged accounts, unavailability of a critical supplier, denial of '
                        . 'service. For each one: the processes it stops, how to keep working without lowering '
                        . 'security, who does what, and which playbook to follow.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Add the default scenarios, then adapt each one: choose the impacted processes, check the '
                        . 'secure degraded mode and the roles, link the response playbook. Every critical process '
                        . 'should have a scenario, and every scenario should be exercised at least once a year.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.5.29', 'grcmanager'),
            ],
            // Issue #616 : modèles de messages de crise et leurs listes de diffusion.
            'crisismessagetemplate' => [
                'purpose'   => __(
                    'Crisis message templates: the messages prepared in advance for each kind of crisis (major '
                        . 'incident, personal data breach, unavailability, ransomware), with the people and '
                        . 'organizations to inform for each one.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Adapt the templates provided and fill in the distribution list of each one (users, groups and '
                        . 'official contacts). During a crisis, open the Crisis communication tab of the incident: '
                        . 'the message is filled in from the incident, you send it yourself, then record it. The '
                        . 'plugin never sends any email.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.5.26 and clause 7.4', 'grcmanager'),
            ],
            // Issue #537 : référentiels personnalisés importés depuis un fichier CSV.
            'customframework' => [
                'purpose'  => __(
                    'Custom frameworks: follow a framework that is not built in (group requirements, sector or '
                        . 'contractual framework, customer security plan) by importing it from a spreadsheet.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Download the template, fill in one line per requirement with the Annex A controls that meet '
                        . 'it, import it, then enable the framework. A new version of the file updates it without '
                        . 'losing the statuses already entered.',
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
            'businessprocess' => [
                'purpose'   => __(
                    'Business processes: who owns them, how critical they are, how long they can be interrupted and '
                        . 'which GLPI items they depend on.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'For each process, rate the impact of an interruption over time (business impact analysis), set '
                        . 'its MTPD, RTO and RPO, and link the applications, servers and suppliers it needs; items '
                        . 'slower than the process are reported. Export the result for your continuity plan.',
                    'grcmanager'
                ),
                'reference' => __('ISO 22301, 8.2.2; ISO 27001, Annex A 5.29 and 5.30', 'grcmanager'),
            ],
            // Issue #733 : programme de tests de reprise (front/recoveryprogramme.php).
            'recoveryprogramme' => [
                'purpose'   => __(
                    'Recovery test programme: when each business process was last tested, whether the test met '
                        . 'its RTO and RPO, and which processes are not tested in time.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Plan a test for each process reported as overdue or not tested this year, then record the '
                        . 'measured recovery time and data loss in its "Recovery strategy" tab. Show this view to '
                        . 'the auditor as evidence that ICT continuity is tested regularly.',
                    'grcmanager'
                ),
                'reference' => __('ISO 22301, 8.5; ISO 27001, Annex A 5.30', 'grcmanager'),
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

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Structure officielle du NIST Cybersecurity Framework (CSF) 2.0 (6 Fonctions, 22
 * Catégories, 106 Sous-catégories) — texte intégral des intitulés/objectifs, domaine public
 * (publication du gouvernement américain, aucune restriction de reproduction, contrairement à
 * ISO 27002). Sourcé sur le catalogue OSCAL officiel publié par NIST
 * (https://github.com/usnistgov/oscal-content, nist.gov/CSF/v2.0/json), en filtrant les
 * éléments explicitement marqués `status: withdrawn` (les anciennes catégories/sous-catégories
 * de CSF 1.1 conservées dans le fichier à des fins historiques/de transition, ex. ID.BE, ID.GV,
 * PR.AC, PR.IP — absentes du CSF 2.0 réel et donc absentes d'ici).
 *
 * Catalogue de référence, purement consultable — voir ControlCrosswalkDefaults pour la
 * correspondance avec l'Annexe A ISO 27001 (PluginGrcmanagerControl).
 */
final class NistCsfCatalogDefaults
{
    /** @var array<string, string> Code de fonction => nom. */
    public const FUNCTIONS = [
        'GV' => 'GOVERN',
        'ID' => 'IDENTIFY',
        'PR' => 'PROTECT',
        'DE' => 'DETECT',
        'RS' => 'RESPOND',
        'RC' => 'RECOVER',
    ];

    /** @var array<string, array{function: string, name: string}> */
    public const CATEGORIES = [
        'GV.OC' => ['function' => 'GV', 'name' => 'Organizational Context'],
        'GV.OV' => ['function' => 'GV', 'name' => 'Oversight'],
        'GV.PO' => ['function' => 'GV', 'name' => 'Policy'],
        'GV.RM' => ['function' => 'GV', 'name' => 'Risk Management Strategy'],
        'GV.RR' => ['function' => 'GV', 'name' => 'Roles, Responsibilities, and Authorities'],
        'GV.SC' => ['function' => 'GV', 'name' => 'Cybersecurity Supply Chain Risk Management'],
        'ID.AM' => ['function' => 'ID', 'name' => 'Asset Management'],
        'ID.IM' => ['function' => 'ID', 'name' => 'Improvement'],
        'ID.RA' => ['function' => 'ID', 'name' => 'Risk Assessment'],
        'PR.AA' => ['function' => 'PR', 'name' => 'Identity Management, Authentication, and Access Control'],
        'PR.AT' => ['function' => 'PR', 'name' => 'Awareness and Training'],
        'PR.DS' => ['function' => 'PR', 'name' => 'Data Security'],
        'PR.IR' => ['function' => 'PR', 'name' => 'Technology Infrastructure Resilience'],
        'PR.PS' => ['function' => 'PR', 'name' => 'Platform Security'],
        'DE.AE' => ['function' => 'DE', 'name' => 'Adverse Event Analysis'],
        'DE.CM' => ['function' => 'DE', 'name' => 'Continuous Monitoring'],
        'RS.AN' => ['function' => 'RS', 'name' => 'Incident Analysis'],
        'RS.CO' => ['function' => 'RS', 'name' => 'Incident Response Reporting and Communication'],
        'RS.MA' => ['function' => 'RS', 'name' => 'Incident Management'],
        'RS.MI' => ['function' => 'RS', 'name' => 'Incident Mitigation'],
        'RC.CO' => ['function' => 'RC', 'name' => 'Incident Recovery Communication'],
        'RC.RP' => ['function' => 'RC', 'name' => 'Incident Recovery Plan Execution'],
    ];

    /** @var array<string, array{category: string, text: string}> */
    public const SUBCATEGORIES = [
        'GV.OC-01' => [
            'category' => 'GV.OC',
            'text' => 'The organizational mission is understood and informs cybersecurity risk management',
        ],
        'GV.OC-02' => [
            'category' => 'GV.OC',
            'text' => 'Internal and external stakeholders are understood, and their needs and expectations '
            . 'regarding cybersecurity risk management are understood and considered',
        ],
        'GV.OC-03' => [
            'category' => 'GV.OC',
            'text' => 'Legal, regulatory, and contractual requirements regarding cybersecurity - including '
            . 'privacy and civil liberties obligations - are understood and managed',
        ],
        'GV.OC-04' => [
            'category' => 'GV.OC',
            'text' => 'Critical objectives, capabilities, and services that external stakeholders depend on or '
            . 'expect from the organization are understood and communicated',
        ],
        'GV.OC-05' => [
            'category' => 'GV.OC',
            'text' => 'Outcomes, capabilities, and services that the organization depends on are understood '
            . 'and communicated',
        ],
        'GV.OV-01' => [
            'category' => 'GV.OV',
            'text' => 'Cybersecurity risk management strategy outcomes are reviewed to inform and adjust '
            . 'strategy and direction',
        ],
        'GV.OV-02' => [
            'category' => 'GV.OV',
            'text' => 'The cybersecurity risk management strategy is reviewed and adjusted to ensure coverage '
            . 'of organizational requirements and risks',
        ],
        'GV.OV-03' => [
            'category' => 'GV.OV',
            'text' => 'Organizational cybersecurity risk management performance is evaluated and reviewed for '
            . 'adjustments needed',
        ],
        'GV.PO-01' => [
            'category' => 'GV.PO',
            'text' => 'Policy for managing cybersecurity risks is established based on organizational context, '
            . 'cybersecurity strategy, and priorities and is communicated and enforced',
        ],
        'GV.PO-02' => [
            'category' => 'GV.PO',
            'text' => 'Policy for managing cybersecurity risks is reviewed, updated, communicated, and '
            . 'enforced to reflect changes in requirements, threats, technology, and organizational '
            . 'mission',
        ],
        'GV.RM-01' => [
            'category' => 'GV.RM',
            'text' => 'Risk management objectives are established and agreed to by organizational stakeholders',
        ],
        'GV.RM-02' => [
            'category' => 'GV.RM',
            'text' => 'Risk appetite and risk tolerance statements are established, communicated, and '
            . 'maintained',
        ],
        'GV.RM-03' => [
            'category' => 'GV.RM',
            'text' => 'Cybersecurity risk management activities and outcomes are included in enterprise risk '
            . 'management processes',
        ],
        'GV.RM-04' => [
            'category' => 'GV.RM',
            'text' => 'Strategic direction that describes appropriate risk response options is established and '
            . 'communicated',
        ],
        'GV.RM-05' => [
            'category' => 'GV.RM',
            'text' => 'Lines of communication across the organization are established for cybersecurity risks, '
            . 'including risks from suppliers and other third parties',
        ],
        'GV.RM-06' => [
            'category' => 'GV.RM',
            'text' => 'A standardized method for calculating, documenting, categorizing, and prioritizing '
            . 'cybersecurity risks is established and communicated',
        ],
        'GV.RM-07' => [
            'category' => 'GV.RM',
            'text' => 'Strategic opportunities (i.e., positive risks) are characterized and are included in '
            . 'organizational cybersecurity risk discussions',
        ],
        'GV.RR-01' => [
            'category' => 'GV.RR',
            'text' => 'Organizational leadership is responsible and accountable for cybersecurity risk and '
            . 'fosters a culture that is risk-aware, ethical, and continually improving',
        ],
        'GV.RR-02' => [
            'category' => 'GV.RR',
            'text' => 'Roles, responsibilities, and authorities related to cybersecurity risk management are '
            . 'established, communicated, understood, and enforced',
        ],
        'GV.RR-03' => [
            'category' => 'GV.RR',
            'text' => 'Adequate resources are allocated commensurate with the cybersecurity risk strategy, '
            . 'roles, responsibilities, and policies',
        ],
        'GV.RR-04' => ['category' => 'GV.RR', 'text' => 'Cybersecurity is included in human resources practices'],
        'GV.SC-01' => [
            'category' => 'GV.SC',
            'text' => 'A cybersecurity supply chain risk management program, strategy, objectives, policies, '
            . 'and processes are established and agreed to by organizational stakeholders',
        ],
        'GV.SC-02' => [
            'category' => 'GV.SC',
            'text' => 'Cybersecurity roles and responsibilities for suppliers, customers, and partners are '
            . 'established, communicated, and coordinated internally and externally',
        ],
        'GV.SC-03' => [
            'category' => 'GV.SC',
            'text' => 'Cybersecurity supply chain risk management is integrated into cybersecurity and '
            . 'enterprise risk management, risk assessment, and improvement processes',
        ],
        'GV.SC-04' => ['category' => 'GV.SC', 'text' => 'Suppliers are known and prioritized by criticality'],
        'GV.SC-05' => [
            'category' => 'GV.SC',
            'text' => 'Requirements to address cybersecurity risks in supply chains are established, '
            . 'prioritized, and integrated into contracts and other types of agreements with suppliers '
            . 'and other relevant third parties',
        ],
        'GV.SC-06' => [
            'category' => 'GV.SC',
            'text' => 'Planning and due diligence are performed to reduce risks before entering into formal '
            . 'supplier or other third-party relationships',
        ],
        'GV.SC-07' => [
            'category' => 'GV.SC',
            'text' => 'The risks posed by a supplier, their products and services, and other third parties are '
            . 'understood, recorded, prioritized, assessed, responded to, and monitored over the '
            . 'course of the relationship',
        ],
        'GV.SC-08' => [
            'category' => 'GV.SC',
            'text' => 'Relevant suppliers and other third parties are included in incident planning, response, '
            . 'and recovery activities',
        ],
        'GV.SC-09' => [
            'category' => 'GV.SC',
            'text' => 'Supply chain security practices are integrated into cybersecurity and enterprise risk '
            . 'management programs, and their performance is monitored throughout the technology '
            . 'product and service life cycle',
        ],
        'GV.SC-10' => [
            'category' => 'GV.SC',
            'text' => 'Cybersecurity supply chain risk management plans include provisions for activities that '
            . 'occur after the conclusion of a partnership or service agreement',
        ],
        'ID.AM-01' => [
            'category' => 'ID.AM',
            'text' => 'Inventories of hardware managed by the organization are maintained',
        ],
        'ID.AM-02' => [
            'category' => 'ID.AM',
            'text' => 'Inventories of software, services, and systems managed by the organization are '
            . 'maintained',
        ],
        'ID.AM-03' => [
            'category' => 'ID.AM',
            'text' => 'Representations of the organization\'s authorized network communication and internal '
            . 'and external network data flows are maintained',
        ],
        'ID.AM-04' => ['category' => 'ID.AM', 'text' => 'Inventories of services provided by suppliers are maintained'],
        'ID.AM-05' => [
            'category' => 'ID.AM',
            'text' => 'Assets are prioritized based on classification, criticality, resources, and impact on '
            . 'the mission',
        ],
        'ID.AM-07' => [
            'category' => 'ID.AM',
            'text' => 'Inventories of data and corresponding metadata for designated data types are maintained',
        ],
        'ID.AM-08' => [
            'category' => 'ID.AM',
            'text' => 'Systems, hardware, software, services, and data are managed throughout their life '
            . 'cycles',
        ],
        'ID.IM-01' => ['category' => 'ID.IM', 'text' => 'Improvements are identified from evaluations'],
        'ID.IM-02' => [
            'category' => 'ID.IM',
            'text' => 'Improvements are identified from security tests and exercises, including those done in '
            . 'coordination with suppliers and relevant third parties',
        ],
        'ID.IM-03' => [
            'category' => 'ID.IM',
            'text' => 'Improvements are identified from execution of operational processes, procedures, and '
            . 'activities',
        ],
        'ID.IM-04' => [
            'category' => 'ID.IM',
            'text' => 'Incident response plans and other cybersecurity plans that affect operations are '
            . 'established, communicated, maintained, and improved',
        ],
        'ID.RA-01' => [
            'category' => 'ID.RA',
            'text' => 'Vulnerabilities in assets are identified, validated, and recorded',
        ],
        'ID.RA-02' => [
            'category' => 'ID.RA',
            'text' => 'Cyber threat intelligence is received from information sharing forums and sources',
        ],
        'ID.RA-03' => [
            'category' => 'ID.RA',
            'text' => 'Internal and external threats to the organization are identified and recorded',
        ],
        'ID.RA-04' => [
            'category' => 'ID.RA',
            'text' => 'Potential impacts and likelihoods of threats exploiting vulnerabilities are identified '
            . 'and recorded',
        ],
        'ID.RA-05' => [
            'category' => 'ID.RA',
            'text' => 'Threats, vulnerabilities, likelihoods, and impacts are used to understand inherent risk '
            . 'and inform risk response prioritization',
        ],
        'ID.RA-06' => [
            'category' => 'ID.RA',
            'text' => 'Risk responses are chosen, prioritized, planned, tracked, and communicated',
        ],
        'ID.RA-07' => [
            'category' => 'ID.RA',
            'text' => 'Changes and exceptions are managed, assessed for risk impact, recorded, and tracked',
        ],
        'ID.RA-08' => [
            'category' => 'ID.RA',
            'text' => 'Processes for receiving, analyzing, and responding to vulnerability disclosures are '
            . 'established',
        ],
        'ID.RA-09' => [
            'category' => 'ID.RA',
            'text' => 'The authenticity and integrity of hardware and software are assessed prior to '
            . 'acquisition and use',
        ],
        'ID.RA-10' => ['category' => 'ID.RA', 'text' => 'Critical suppliers are assessed prior to acquisition'],
        'PR.AA-01' => [
            'category' => 'PR.AA',
            'text' => 'Identities and credentials for authorized users, services, and hardware are managed by '
            . 'the organization',
        ],
        'PR.AA-02' => [
            'category' => 'PR.AA',
            'text' => 'Identities are proofed and bound to credentials based on the context of interactions',
        ],
        'PR.AA-03' => ['category' => 'PR.AA', 'text' => 'Users, services, and hardware are authenticated'],
        'PR.AA-04' => ['category' => 'PR.AA', 'text' => 'Identity assertions are protected, conveyed, and verified'],
        'PR.AA-05' => [
            'category' => 'PR.AA',
            'text' => 'Access permissions, entitlements, and authorizations are defined in a policy, managed, '
            . 'enforced, and reviewed, and incorporate the principles of least privilege and '
            . 'separation of duties',
        ],
        'PR.AA-06' => [
            'category' => 'PR.AA',
            'text' => 'Physical access to assets is managed, monitored, and enforced commensurate with risk',
        ],
        'PR.AT-01' => [
            'category' => 'PR.AT',
            'text' => 'Personnel are provided with awareness and training so that they possess the knowledge '
            . 'and skills to perform general tasks with cybersecurity risks in mind',
        ],
        'PR.AT-02' => [
            'category' => 'PR.AT',
            'text' => 'Individuals in specialized roles are provided with awareness and training so that they '
            . 'possess the knowledge and skills to perform relevant tasks with cybersecurity risks in '
            . 'mind',
        ],
        'PR.DS-01' => [
            'category' => 'PR.DS',
            'text' => 'The confidentiality, integrity, and availability of data-at-rest are protected',
        ],
        'PR.DS-02' => [
            'category' => 'PR.DS',
            'text' => 'The confidentiality, integrity, and availability of data-in-transit are protected',
        ],
        'PR.DS-10' => [
            'category' => 'PR.DS',
            'text' => 'The confidentiality, integrity, and availability of data-in-use are protected',
        ],
        'PR.DS-11' => [
            'category' => 'PR.DS',
            'text' => 'Backups of data are created, protected, maintained, and tested',
        ],
        'PR.IR-01' => [
            'category' => 'PR.IR',
            'text' => 'Networks and environments are protected from unauthorized logical access and usage',
        ],
        'PR.IR-02' => [
            'category' => 'PR.IR',
            'text' => 'The organization\'s technology assets are protected from environmental threats',
        ],
        'PR.IR-03' => [
            'category' => 'PR.IR',
            'text' => 'Mechanisms are implemented to achieve resilience requirements in normal and adverse '
            . 'situations',
        ],
        'PR.IR-04' => [
            'category' => 'PR.IR',
            'text' => 'Adequate resource capacity to ensure availability is maintained',
        ],
        'PR.PS-01' => [
            'category' => 'PR.PS',
            'text' => 'Configuration management practices are established and applied',
        ],
        'PR.PS-02' => [
            'category' => 'PR.PS',
            'text' => 'Software is maintained, replaced, and removed commensurate with risk',
        ],
        'PR.PS-03' => [
            'category' => 'PR.PS',
            'text' => 'Hardware is maintained, replaced, and removed commensurate with risk',
        ],
        'PR.PS-04' => [
            'category' => 'PR.PS',
            'text' => 'Log records are generated and made available for continuous monitoring',
        ],
        'PR.PS-05' => [
            'category' => 'PR.PS',
            'text' => 'Installation and execution of unauthorized software are prevented',
        ],
        'PR.PS-06' => [
            'category' => 'PR.PS',
            'text' => 'Secure software development practices are integrated, and their performance is '
            . 'monitored throughout the software development life cycle',
        ],
        'DE.AE-02' => [
            'category' => 'DE.AE',
            'text' => 'Potentially adverse events are analyzed to better understand associated activities',
        ],
        'DE.AE-03' => ['category' => 'DE.AE', 'text' => 'Information is correlated from multiple sources'],
        'DE.AE-04' => [
            'category' => 'DE.AE',
            'text' => 'The estimated impact and scope of adverse events are understood',
        ],
        'DE.AE-06' => [
            'category' => 'DE.AE',
            'text' => 'Information on adverse events is provided to authorized staff and tools',
        ],
        'DE.AE-07' => [
            'category' => 'DE.AE',
            'text' => 'Cyber threat intelligence and other contextual information are integrated into the '
            . 'analysis',
        ],
        'DE.AE-08' => [
            'category' => 'DE.AE',
            'text' => 'Incidents are declared when adverse events meet the defined incident criteria',
        ],
        'DE.CM-01' => [
            'category' => 'DE.CM',
            'text' => 'Networks and network services are monitored to find potentially adverse events',
        ],
        'DE.CM-02' => [
            'category' => 'DE.CM',
            'text' => 'The physical environment is monitored to find potentially adverse events',
        ],
        'DE.CM-03' => [
            'category' => 'DE.CM',
            'text' => 'Personnel activity and technology usage are monitored to find potentially adverse '
            . 'events',
        ],
        'DE.CM-06' => [
            'category' => 'DE.CM',
            'text' => 'External service provider activities and services are monitored to find potentially '
            . 'adverse events',
        ],
        'DE.CM-09' => [
            'category' => 'DE.CM',
            'text' => 'Computing hardware and software, runtime environments, and their data are monitored to '
            . 'find potentially adverse events',
        ],
        'RS.AN-03' => [
            'category' => 'RS.AN',
            'text' => 'Analysis is performed to establish what has taken place during an incident and the root '
            . 'cause of the incident',
        ],
        'RS.AN-06' => [
            'category' => 'RS.AN',
            'text' => 'Actions performed during an investigation are recorded, and the records\' integrity and '
            . 'provenance are preserved',
        ],
        'RS.AN-07' => [
            'category' => 'RS.AN',
            'text' => 'Incident data and metadata are collected, and their integrity and provenance are '
            . 'preserved',
        ],
        'RS.AN-08' => ['category' => 'RS.AN', 'text' => 'An incident\'s magnitude is estimated and validated'],
        'RS.CO-02' => ['category' => 'RS.CO', 'text' => 'Internal and external stakeholders are notified of incidents'],
        'RS.CO-03' => [
            'category' => 'RS.CO',
            'text' => 'Information is shared with designated internal and external stakeholders',
        ],
        'RS.MA-01' => [
            'category' => 'RS.MA',
            'text' => 'The incident response plan is executed in coordination with relevant third parties once '
            . 'an incident is declared',
        ],
        'RS.MA-02' => ['category' => 'RS.MA', 'text' => 'Incident reports are triaged and validated'],
        'RS.MA-03' => ['category' => 'RS.MA', 'text' => 'Incidents are categorized and prioritized'],
        'RS.MA-04' => ['category' => 'RS.MA', 'text' => 'Incidents are escalated or elevated as needed'],
        'RS.MA-05' => ['category' => 'RS.MA', 'text' => 'The criteria for initiating incident recovery are applied'],
        'RS.MI-01' => ['category' => 'RS.MI', 'text' => 'Incidents are contained'],
        'RS.MI-02' => ['category' => 'RS.MI', 'text' => 'Incidents are eradicated'],
        'RC.CO-03' => [
            'category' => 'RC.CO',
            'text' => 'Recovery activities and progress in restoring operational capabilities are communicated '
            . 'to designated internal and external stakeholders',
        ],
        'RC.CO-04' => [
            'category' => 'RC.CO',
            'text' => 'Public updates on incident recovery are shared using approved methods and messaging',
        ],
        'RC.RP-01' => [
            'category' => 'RC.RP',
            'text' => 'The recovery portion of the incident response plan is executed once initiated from the '
            . 'incident response process',
        ],
        'RC.RP-02' => [
            'category' => 'RC.RP',
            'text' => 'Recovery actions are selected, scoped, prioritized, and performed',
        ],
        'RC.RP-03' => [
            'category' => 'RC.RP',
            'text' => 'The integrity of backups and other restoration assets is verified before using them for '
            . 'restoration',
        ],
        'RC.RP-04' => [
            'category' => 'RC.RP',
            'text' => 'Critical mission functions and cybersecurity risk management are considered to '
            . 'establish post-incident operational norms',
        ],
        'RC.RP-05' => [
            'category' => 'RC.RP',
            'text' => 'The integrity of restored assets is verified, systems and services are restored, and '
            . 'normal operating status is confirmed',
        ],
        'RC.RP-06' => [
            'category' => 'RC.RP',
            'text' => 'The end of incident recovery is declared based on criteria, and incident-related '
            . 'documentation is completed',
        ],
    ];
}

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * CIS Critical Security Controls v8 (18 Contrôles, 153 Sauvegardes/Safeguards) — intitulés
 * publiés librement par le Center for Internet Security pour l'adoption communautaire (comptes
 * 18/153 vérifiés). Le niveau de groupe de mise en œuvre (`ig`, 1/2/3) de chaque sauvegarde
 * vient du dépôt officiel du Center for Internet Security lui-même
 * (github.com/CISecurity/ControlsAssessmentSpecification), pas des résumés tiers largement
 * cités ("56 en IG1, 74 de plus en IG2, 23 de plus en IG3") qui datent de la sortie initiale
 * de v8 en 2021 — CIS a depuis légèrement rééquilibré quelques sauvegardes entre groupes (ex.
 * 14.7/14.8/14.9/17.9 rétrogradées vers IG1, 9.7/15.5 montées vers IG3), d'où la répartition
 * réelle ici (58/73/22) qui ne correspond plus à ce chiffre devenu obsolète.
 *
 * Catalogue de référence, purement consultable — contrairement à NistCsfCatalogDefaults, PAS
 * de correspondance avec l'Annexe A ISO 27001 dans ControlCrosswalkDefaults : CIS ne publie sa
 * correspondance officielle vers ISO/IEC 27001:2022 que dans un livre blanc nécessitant la
 * création d'un compte (learn.cisecurity.org), non récupérable automatiquement — voir le
 * docblock de ControlCrosswalkDefaults.
 */
final class CisControlsCatalogDefaults
{
    /** @var array<int, string> Numéro de contrôle => intitulé. */
    public const CONTROLS = [
        1 => 'Inventory and Control of Enterprise Assets',
        2 => 'Inventory and Control of Software Assets',
        3 => 'Data Protection',
        4 => 'Secure Configuration of Enterprise Assets and Software',
        5 => 'Account Management',
        6 => 'Access Control Management',
        7 => 'Continuous Vulnerability Management',
        8 => 'Audit Log Management',
        9 => 'Email and Web Browser Protections',
        10 => 'Malware Defenses',
        11 => 'Data Recovery',
        12 => 'Network Infrastructure Management',
        13 => 'Network Monitoring and Defense',
        14 => 'Security Awareness and Skills Training',
        15 => 'Service Provider Management',
        16 => 'Application Software Security',
        17 => 'Incident Response Management',
        18 => 'Penetration Testing',
    ];

    /** @var array<string, array{control: int, text: string, ig: int}> */
    public const SAFEGUARDS = [
        '1.1' => ['control' => 1, 'text' => 'Establish and Maintain Detailed Enterprise Asset Inventory', 'ig' => 1],
        '1.2' => ['control' => 1, 'text' => 'Address Unauthorized Assets', 'ig' => 1],
        '1.3' => ['control' => 1, 'text' => 'Utilize an Active Discovery Tool', 'ig' => 2],
        '1.4' => [
            'control' => 1,
            'text' => 'Use Dynamic Host Configuration Protocol (DHCP) Logging to Update Enterprise Asset '
            . 'Inventory',
            'ig' => 2,
        ],
        '1.5' => ['control' => 1, 'text' => 'Use a Passive Asset Discovery Tool', 'ig' => 3],
        '2.1' => ['control' => 2, 'text' => 'Establish and Maintain a Software Inventory', 'ig' => 1],
        '2.2' => ['control' => 2, 'text' => 'Ensure Authorized Software is Currently Supported', 'ig' => 1],
        '2.3' => ['control' => 2, 'text' => 'Address Unauthorized Software', 'ig' => 1],
        '2.4' => ['control' => 2, 'text' => 'Utilize Automated Software Inventory Tools', 'ig' => 2],
        '2.5' => ['control' => 2, 'text' => 'Allowlist Authorized Software', 'ig' => 2],
        '2.6' => ['control' => 2, 'text' => 'Allowlist Authorized Libraries', 'ig' => 2],
        '2.7' => ['control' => 2, 'text' => 'Allowlist Authorized Scripts', 'ig' => 3],
        '3.1' => ['control' => 3, 'text' => 'Establish and Maintain a Data Management Process', 'ig' => 1],
        '3.2' => ['control' => 3, 'text' => 'Establish and Maintain a Data Inventory', 'ig' => 1],
        '3.3' => ['control' => 3, 'text' => 'Configure Data Access Control Lists', 'ig' => 1],
        '3.4' => ['control' => 3, 'text' => 'Enforce Data Retention', 'ig' => 1],
        '3.5' => ['control' => 3, 'text' => 'Securely Dispose of Data', 'ig' => 1],
        '3.6' => ['control' => 3, 'text' => 'Encrypt Data on End-User Devices', 'ig' => 1],
        '3.7' => ['control' => 3, 'text' => 'Establish and Maintain a Data Classification Scheme', 'ig' => 2],
        '3.8' => ['control' => 3, 'text' => 'Document Data Flows', 'ig' => 2],
        '3.9' => ['control' => 3, 'text' => 'Encrypt Data on Removable Media', 'ig' => 2],
        '3.10' => ['control' => 3, 'text' => 'Encrypt Sensitive Data in Transit', 'ig' => 2],
        '3.11' => ['control' => 3, 'text' => 'Encrypt Sensitive Data at Rest', 'ig' => 2],
        '3.12' => ['control' => 3, 'text' => 'Segment Data Processing and Storage Based on Sensitivity', 'ig' => 2],
        '3.13' => ['control' => 3, 'text' => 'Deploy a Data Loss Prevention Solution', 'ig' => 3],
        '3.14' => ['control' => 3, 'text' => 'Log Sensitive Data Access', 'ig' => 3],
        '4.1' => ['control' => 4, 'text' => 'Establish and Maintain a Secure Configuration Process', 'ig' => 1],
        '4.2' => [
            'control' => 4,
            'text' => 'Establish and Maintain a Secure Configuration Process for Network Infrastructure',
            'ig' => 1,
        ],
        '4.3' => ['control' => 4, 'text' => 'Configure Automatic Session Locking on Enterprise Assets', 'ig' => 1],
        '4.4' => ['control' => 4, 'text' => 'Implement and Manage a Firewall on Servers', 'ig' => 1],
        '4.5' => ['control' => 4, 'text' => 'Implement and Manage a Firewall on End-User Devices', 'ig' => 1],
        '4.6' => ['control' => 4, 'text' => 'Securely Manage Enterprise Assets and Software', 'ig' => 1],
        '4.7' => ['control' => 4, 'text' => 'Manage Default Accounts on Enterprise Assets and Software', 'ig' => 1],
        '4.8' => [
            'control' => 4,
            'text' => 'Uninstall or Disable Unnecessary Services on Enterprise Assets and Software',
            'ig' => 2,
        ],
        '4.9' => ['control' => 4, 'text' => 'Configure Trusted DNS Servers on Enterprise Assets', 'ig' => 2],
        '4.10' => [
            'control' => 4,
            'text' => 'Enforce Automatic Device Lockout on Portable End-User Devices',
            'ig' => 2,
        ],
        '4.11' => ['control' => 4, 'text' => 'Enforce Remote Wipe Capability on Portable End-User Devices', 'ig' => 2],
        '4.12' => ['control' => 4, 'text' => 'Separate Enterprise Workspaces on Mobile End-User Devices', 'ig' => 3],
        '5.1' => ['control' => 5, 'text' => 'Establish and Maintain an Inventory of Accounts', 'ig' => 1],
        '5.2' => ['control' => 5, 'text' => 'Use Unique Passwords', 'ig' => 1],
        '5.3' => ['control' => 5, 'text' => 'Disable Dormant Accounts', 'ig' => 1],
        '5.4' => [
            'control' => 5,
            'text' => 'Restrict Administrator Privileges to Dedicated Administrator Accounts',
            'ig' => 1,
        ],
        '5.5' => ['control' => 5, 'text' => 'Establish and Maintain an Inventory of Service Accounts', 'ig' => 2],
        '5.6' => ['control' => 5, 'text' => 'Centralize Account Management', 'ig' => 2],
        '6.1' => ['control' => 6, 'text' => 'Establish an Access Granting Process', 'ig' => 1],
        '6.2' => ['control' => 6, 'text' => 'Establish an Access Revoking Process', 'ig' => 1],
        '6.3' => ['control' => 6, 'text' => 'Require MFA for Externally-Exposed Applications', 'ig' => 1],
        '6.4' => ['control' => 6, 'text' => 'Require MFA for Remote Network Access', 'ig' => 1],
        '6.5' => ['control' => 6, 'text' => 'Require MFA for Administrative Access', 'ig' => 1],
        '6.6' => [
            'control' => 6,
            'text' => 'Establish and Maintain an Inventory of Authentication and Authorization Systems',
            'ig' => 2,
        ],
        '6.7' => ['control' => 6, 'text' => 'Centralize Access Control', 'ig' => 2],
        '6.8' => ['control' => 6, 'text' => 'Define and Maintain Role-Based Access Control', 'ig' => 3],
        '7.1' => ['control' => 7, 'text' => 'Establish and Maintain a Vulnerability Management Process', 'ig' => 1],
        '7.2' => ['control' => 7, 'text' => 'Establish and Maintain a Remediation Process', 'ig' => 1],
        '7.3' => ['control' => 7, 'text' => 'Perform Automated Operating System Patch Management', 'ig' => 1],
        '7.4' => ['control' => 7, 'text' => 'Perform Automated Application Patch Management', 'ig' => 1],
        '7.5' => [
            'control' => 7,
            'text' => 'Perform Automated Vulnerability Scans of Internal Enterprise Assets',
            'ig' => 2,
        ],
        '7.6' => [
            'control' => 7,
            'text' => 'Perform Automated Vulnerability Scans of Externally-Exposed Enterprise Assets',
            'ig' => 2,
        ],
        '7.7' => ['control' => 7, 'text' => 'Remediate Detected Vulnerabilities', 'ig' => 2],
        '8.1' => ['control' => 8, 'text' => 'Establish and Maintain an Audit Log Management Process', 'ig' => 1],
        '8.2' => ['control' => 8, 'text' => 'Collect Audit Logs', 'ig' => 1],
        '8.3' => ['control' => 8, 'text' => 'Ensure Adequate Audit Log Storage', 'ig' => 1],
        '8.4' => ['control' => 8, 'text' => 'Standardize Time Synchronization', 'ig' => 2],
        '8.5' => ['control' => 8, 'text' => 'Collect Detailed Audit Logs', 'ig' => 2],
        '8.6' => ['control' => 8, 'text' => 'Collect DNS Query Audit Logs', 'ig' => 2],
        '8.7' => ['control' => 8, 'text' => 'Collect URL Request Audit Logs', 'ig' => 2],
        '8.8' => ['control' => 8, 'text' => 'Collect Command-Line Audit Logs', 'ig' => 2],
        '8.9' => ['control' => 8, 'text' => 'Centralize Audit Logs', 'ig' => 2],
        '8.10' => ['control' => 8, 'text' => 'Retain Audit Logs', 'ig' => 2],
        '8.11' => ['control' => 8, 'text' => 'Conduct Audit Log Reviews', 'ig' => 2],
        '8.12' => ['control' => 8, 'text' => 'Collect Service Provider Logs', 'ig' => 3],
        '9.1' => ['control' => 9, 'text' => 'Ensure Use of Only Fully Supported Browsers and Email Clients', 'ig' => 1],
        '9.2' => ['control' => 9, 'text' => 'Use DNS Filtering Services', 'ig' => 1],
        '9.3' => ['control' => 9, 'text' => 'Maintain and Enforce Network-Based URL Filters', 'ig' => 2],
        '9.4' => [
            'control' => 9,
            'text' => 'Restrict Unnecessary or Unauthorized Browser and Email Client Extensions',
            'ig' => 2,
        ],
        '9.5' => ['control' => 9, 'text' => 'Implement DMARC', 'ig' => 2],
        '9.6' => ['control' => 9, 'text' => 'Block Unnecessary File Types', 'ig' => 2],
        '9.7' => ['control' => 9, 'text' => 'Deploy and Maintain Email Server Anti-Malware Protections', 'ig' => 3],
        '10.1' => ['control' => 10, 'text' => 'Deploy and Maintain Anti-Malware Software', 'ig' => 1],
        '10.2' => ['control' => 10, 'text' => 'Configure Automatic Anti-Malware Signature Updates', 'ig' => 1],
        '10.3' => ['control' => 10, 'text' => 'Disable Autorun and Autoplay for Removable Media', 'ig' => 1],
        '10.4' => [
            'control' => 10,
            'text' => 'Configure Automatic Anti-Malware Scanning of Removable Media',
            'ig' => 2,
        ],
        '10.5' => ['control' => 10, 'text' => 'Enable Anti-Exploitation Features', 'ig' => 2],
        '10.6' => ['control' => 10, 'text' => 'Centrally Manage Anti-Malware Software', 'ig' => 2],
        '10.7' => ['control' => 10, 'text' => 'Use Behavior-Based Anti-Malware Software', 'ig' => 2],
        '11.1' => ['control' => 11, 'text' => 'Establish and Maintain a Data Recovery Process', 'ig' => 1],
        '11.2' => ['control' => 11, 'text' => 'Perform Automated Backups', 'ig' => 1],
        '11.3' => ['control' => 11, 'text' => 'Protect Recovery Data', 'ig' => 1],
        '11.4' => [
            'control' => 11,
            'text' => 'Establish and Maintain an Isolated Instance of Recovery Data',
            'ig' => 1,
        ],
        '11.5' => ['control' => 11, 'text' => 'Test Data Recovery', 'ig' => 2],
        '12.1' => ['control' => 12, 'text' => 'Ensure Network Infrastructure is Up-to-Date', 'ig' => 1],
        '12.2' => ['control' => 12, 'text' => 'Establish and Maintain a Secure Network Architecture', 'ig' => 2],
        '12.3' => ['control' => 12, 'text' => 'Securely Manage Network Infrastructure', 'ig' => 2],
        '12.4' => ['control' => 12, 'text' => 'Establish and Maintain Architecture Diagram(s)', 'ig' => 2],
        '12.5' => [
            'control' => 12,
            'text' => 'Centralize Network Authentication, Authorization, and Auditing (AAA)',
            'ig' => 2,
        ],
        '12.6' => [
            'control' => 12,
            'text' => 'Use of Secure Network Management and Communication Protocols',
            'ig' => 2,
        ],
        '12.7' => [
            'control' => 12,
            'text' => 'Ensure Remote Devices Utilize a VPN and are Connecting to an Enterprise\'s AAA '
            . 'Infrastructure',
            'ig' => 2,
        ],
        '12.8' => [
            'control' => 12,
            'text' => 'Establish and Maintain Dedicated Computing Resources for All Administrative Work',
            'ig' => 3,
        ],
        '13.1' => ['control' => 13, 'text' => 'Centralize Security Event Alerting', 'ig' => 2],
        '13.2' => ['control' => 13, 'text' => 'Deploy a Host-Based Intrusion Detection Solution', 'ig' => 2],
        '13.3' => ['control' => 13, 'text' => 'Deploy a Network Intrusion Detection Solution', 'ig' => 2],
        '13.4' => ['control' => 13, 'text' => 'Perform Traffic Filtering Between Network Segments', 'ig' => 2],
        '13.5' => ['control' => 13, 'text' => 'Manage Access Control for Remote Assets', 'ig' => 2],
        '13.6' => ['control' => 13, 'text' => 'Collect Network Traffic Flow Logs', 'ig' => 2],
        '13.7' => ['control' => 13, 'text' => 'Deploy a Host-Based Intrusion Prevention Solution', 'ig' => 3],
        '13.8' => ['control' => 13, 'text' => 'Deploy a Network Intrusion Prevention Solution', 'ig' => 3],
        '13.9' => ['control' => 13, 'text' => 'Deploy Port-Level Access Control', 'ig' => 3],
        '13.10' => ['control' => 13, 'text' => 'Perform Application Layer Filtering', 'ig' => 3],
        '13.11' => ['control' => 13, 'text' => 'Tune Security Event Alerting Thresholds', 'ig' => 3],
        '14.1' => ['control' => 14, 'text' => 'Establish and Maintain a Security Awareness Program', 'ig' => 1],
        '14.2' => [
            'control' => 14,
            'text' => 'Train Workforce Members to Recognize Social Engineering Attacks',
            'ig' => 1,
        ],
        '14.3' => ['control' => 14, 'text' => 'Train Workforce Members on Authentication Best Practices', 'ig' => 1],
        '14.4' => ['control' => 14, 'text' => 'Train Workforce Members on Data Handling Best Practices', 'ig' => 1],
        '14.5' => [
            'control' => 14,
            'text' => 'Train Workforce Members on Causes of Unintentional Data Exposure',
            'ig' => 1,
        ],
        '14.6' => [
            'control' => 14,
            'text' => 'Train Workforce Members on Recognizing and Reporting Security Incidents',
            'ig' => 1,
        ],
        '14.7' => [
            'control' => 14,
            'text' => 'Train Workforce on How to Identify and Report if Their Enterprise Assets are Missing '
            . 'Security Updates',
            'ig' => 1,
        ],
        '14.8' => [
            'control' => 14,
            'text' => 'Train Workforce on the Dangers of Connecting to and Transmitting Enterprise Data Over '
            . 'Insecure Networks',
            'ig' => 1,
        ],
        '14.9' => [
            'control' => 14,
            'text' => 'Conduct Role-Specific Security Awareness and Skills Training',
            'ig' => 1,
        ],
        '15.1' => ['control' => 15, 'text' => 'Establish and Maintain an Inventory of Service Providers', 'ig' => 1],
        '15.2' => ['control' => 15, 'text' => 'Establish and Maintain a Service Provider Management Policy', 'ig' => 2],
        '15.3' => ['control' => 15, 'text' => 'Classify Service Providers', 'ig' => 2],
        '15.4' => [
            'control' => 15,
            'text' => 'Ensure Service Provider Contracts Include Security Requirements',
            'ig' => 2,
        ],
        '15.5' => ['control' => 15, 'text' => 'Assess Service Providers', 'ig' => 3],
        '15.6' => ['control' => 15, 'text' => 'Monitor Service Providers', 'ig' => 3],
        '15.7' => ['control' => 15, 'text' => 'Securely Decommission Service Providers', 'ig' => 3],
        '16.1' => [
            'control' => 16,
            'text' => 'Establish and Maintain a Secure Application Development Process',
            'ig' => 2,
        ],
        '16.2' => [
            'control' => 16,
            'text' => 'Establish and Maintain a Process to Accept and Address Software Vulnerabilities',
            'ig' => 2,
        ],
        '16.3' => ['control' => 16, 'text' => 'Perform Root Cause Analysis on Security Vulnerabilities', 'ig' => 2],
        '16.4' => [
            'control' => 16,
            'text' => 'Establish and Manage an Inventory of Third-Party Software Components',
            'ig' => 2,
        ],
        '16.5' => ['control' => 16, 'text' => 'Use Up-to-Date and Trusted Third-Party Software Components', 'ig' => 2],
        '16.6' => [
            'control' => 16,
            'text' => 'Establish and Maintain a Severity Rating System and Process for Application '
            . 'Vulnerabilities',
            'ig' => 2,
        ],
        '16.7' => [
            'control' => 16,
            'text' => 'Use Standard Hardening Configuration Templates for Application Infrastructure',
            'ig' => 2,
        ],
        '16.8' => ['control' => 16, 'text' => 'Separate Production and Non-Production Systems', 'ig' => 2],
        '16.9' => [
            'control' => 16,
            'text' => 'Train Developers in Application Security Concepts and Secure Coding',
            'ig' => 2,
        ],
        '16.10' => [
            'control' => 16,
            'text' => 'Apply Secure Design Principles in Application Architectures',
            'ig' => 2,
        ],
        '16.11' => [
            'control' => 16,
            'text' => 'Leverage Vetted Modules or Services for Application Security Components',
            'ig' => 2,
        ],
        '16.12' => ['control' => 16, 'text' => 'Implement Code-Level Security Checks', 'ig' => 3],
        '16.13' => ['control' => 16, 'text' => 'Conduct Application Penetration Testing', 'ig' => 3],
        '16.14' => ['control' => 16, 'text' => 'Conduct Threat Modeling', 'ig' => 3],
        '17.1' => ['control' => 17, 'text' => 'Designate Personnel to Manage Incident Handling', 'ig' => 1],
        '17.2' => [
            'control' => 17,
            'text' => 'Establish and Maintain Contact Information for Reporting Security Incidents',
            'ig' => 1,
        ],
        '17.3' => [
            'control' => 17,
            'text' => 'Establish and Maintain an Enterprise Process for Reporting Incidents',
            'ig' => 1,
        ],
        '17.4' => ['control' => 17, 'text' => 'Establish and Maintain an Incident Response Process', 'ig' => 2],
        '17.5' => ['control' => 17, 'text' => 'Assign Key Roles and Responsibilities', 'ig' => 2],
        '17.6' => [
            'control' => 17,
            'text' => 'Define Mechanisms for Communicating During Incident Response',
            'ig' => 2,
        ],
        '17.7' => ['control' => 17, 'text' => 'Conduct Routine Incident Response Exercises', 'ig' => 2],
        '17.8' => ['control' => 17, 'text' => 'Conduct Post-Incident Reviews', 'ig' => 2],
        '17.9' => ['control' => 17, 'text' => 'Establish and Maintain Security Incident Thresholds', 'ig' => 1],
        '18.1' => ['control' => 18, 'text' => 'Establish and Maintain a Penetration Testing Program', 'ig' => 2],
        '18.2' => ['control' => 18, 'text' => 'Perform Periodic External Penetration Tests', 'ig' => 2],
        '18.3' => ['control' => 18, 'text' => 'Remediate Penetration Test Findings', 'ig' => 2],
        '18.4' => ['control' => 18, 'text' => 'Validate Security Measures', 'ig' => 3],
        '18.5' => ['control' => 18, 'text' => 'Perform Periodic Internal Penetration Tests', 'ig' => 3],
    ];
}

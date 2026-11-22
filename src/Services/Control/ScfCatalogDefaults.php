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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #878 : Secure Controls Framework (SCF), métacadre de mesures du SCF Council qui couvre plus de
 * deux cents lois, règlements et normes. Ses 34 domaines sont repris ici, au niveau du domaine
 * seulement (pas les mesures).
 *
 * Droits : contenu sous licence Creative Commons Attribution - Pas de modification 4.0 (CC BY-ND
 * 4.0). Les identifiants et les noms des domaines sont donc reproduits tels que publiés, en anglais
 * dans toutes les langues (une traduction serait une modification), avec l'attribution affichée sous
 * la carte de templates/referentiels.html.twig. La correspondance `annex_a` avec ISO/IEC 27001:2022
 * est indicative et établie par ce projet : elle ne reprend pas les correspondances (STRM) publiées
 * par le SCF. Une liste vide signale un domaine porté par les chapitres 4 à 10 de la norme.
 */
final class ScfCatalogDefaults
{
    public const SOURCE_URL = 'https://securecontrolsframework.com/domains-principles/';

    public const LICENSE_URL = 'https://creativecommons.org/licenses/by-nd/4.0/';

    /**
     * @var array<string, array{fr: string, en: string}> Regroupement d'affichage (mots de ce projet).
     */
    public const GROUPS = [
        'domains' => ['fr' => 'Domaines du SCF', 'en' => 'SCF domains'],
    ];

    /** Modules du plugin qu'un domaine peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'riskcatalog', 'impactanalysis', 'exercises', 'audits', 'nonconformities', 'processing', 'dpia',
        'requests', 'accounthygiene', 'incidents', 'networkflows', 'risks', 'trainings', 'policies', 'reviews',
        'suppliers', 'vulnerabilities',
    ];

    /**
     * Identifiant du domaine => nom officiel (identique en fr et en, licence sans modification),
     * mesures de l'Annexe A ISO 27001 concernées (correspondance de ce projet) et modules du plugin.
     *
     * @var array<string, array{group: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const DOMAINS = [
        'GOV' => self::NAMES['GOV'] + ['annex_a' => ['A.5.1', 'A.5.2', 'A.5.4'], 'modules' => ['policies', 'reviews']],
        'AAT' => self::NAMES['AAT'] + ['annex_a' => ['A.5.8'], 'modules' => ['riskcatalog']],
        'AST' => self::NAMES['AST'] + ['annex_a' => ['A.5.9', 'A.5.10', 'A.5.11'], 'modules' => []],
        'BCD' => self::NAMES['BCD'] + [
            'annex_a' => ['A.5.29', 'A.5.30', 'A.8.13', 'A.8.14'], 'modules' => ['impactanalysis', 'exercises'],
        ],
        'CAP' => self::NAMES['CAP'] + ['annex_a' => ['A.8.6'], 'modules' => []],
        'CHG' => self::NAMES['CHG'] + ['annex_a' => ['A.8.32'], 'modules' => []],
        'CLD' => self::NAMES['CLD'] + ['annex_a' => ['A.5.23'], 'modules' => ['suppliers']],
        'CPL' => self::NAMES['CPL'] + [
            'annex_a' => ['A.5.31', 'A.5.35', 'A.5.36'], 'modules' => ['audits', 'nonconformities'],
        ],
        'CFG' => self::NAMES['CFG'] + ['annex_a' => ['A.8.9'], 'modules' => []],
        'MON' => self::NAMES['MON'] + ['annex_a' => ['A.8.15', 'A.8.16'], 'modules' => []],
        'CRY' => self::NAMES['CRY'] + ['annex_a' => ['A.8.24'], 'modules' => []],
        'DCH' => self::NAMES['DCH'] + ['annex_a' => ['A.5.10', 'A.5.12', 'A.5.13'], 'modules' => []],
        'PRI' => self::NAMES['PRI'] + ['annex_a' => ['A.5.34'], 'modules' => ['processing', 'dpia', 'requests']],
        'EMB' => self::NAMES['EMB'] + ['annex_a' => ['A.8.9'], 'modules' => []],
        'END' => self::NAMES['END'] + ['annex_a' => ['A.8.1', 'A.8.7'], 'modules' => []],
        'HRS' => self::NAMES['HRS'] + [
            'annex_a' => ['A.6.1', 'A.6.2', 'A.6.4', 'A.6.5', 'A.6.6'], 'modules' => [],
        ],
        'IAC' => self::NAMES['IAC'] + ['annex_a' => ['A.5.16', 'A.5.17', 'A.8.5'], 'modules' => ['accounthygiene']],
        'IRO' => self::NAMES['IRO'] + [
            'annex_a' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28'], 'modules' => ['incidents'],
        ],
        'IAO' => self::NAMES['IAO'] + ['annex_a' => ['A.5.35', 'A.8.29'], 'modules' => ['audits']],
        'MNT' => self::NAMES['MNT'] + ['annex_a' => ['A.7.13'], 'modules' => []],
        'MDM' => self::NAMES['MDM'] + ['annex_a' => ['A.8.1', 'A.6.7'], 'modules' => []],
        'NET' => self::NAMES['NET'] + ['annex_a' => ['A.8.20', 'A.8.21', 'A.8.22'], 'modules' => ['networkflows']],
        'PES' => self::NAMES['PES'] + ['annex_a' => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.4'], 'modules' => []],
        'PRM' => self::NAMES['PRM'] + ['annex_a' => ['A.5.8'], 'modules' => []],
        'QTS' => self::NAMES['QTS'] + ['annex_a' => ['A.8.24'], 'modules' => []],
        'RSK' => self::NAMES['RSK'] + ['annex_a' => [], 'modules' => ['risks', 'riskcatalog']],
        'SEA' => self::NAMES['SEA'] + ['annex_a' => ['A.8.27'], 'modules' => []],
        'SAT' => self::NAMES['SAT'] + ['annex_a' => ['A.6.3'], 'modules' => ['trainings']],
        'OPS' => self::NAMES['OPS'] + ['annex_a' => ['A.5.37'], 'modules' => []],
        'TDA' => self::NAMES['TDA'] + ['annex_a' => ['A.8.25', 'A.8.26', 'A.8.28', 'A.8.30'], 'modules' => []],
        'TPM' => self::NAMES['TPM'] + [
            'annex_a' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22'], 'modules' => ['suppliers'],
        ],
        'THR' => self::NAMES['THR'] + ['annex_a' => ['A.5.7'], 'modules' => []],
        'VPM' => self::NAMES['VPM'] + ['annex_a' => ['A.8.8'], 'modules' => ['vulnerabilities']],
        'WEB' => self::NAMES['WEB'] + ['annex_a' => ['A.8.23', 'A.8.26'], 'modules' => []],
    ];

    /**
     * Noms officiels des domaines, tels que publiés par le SCF Council (non traduits).
     *
     * @var array<string, array{group: string, fr: string, en: string}>
     */
    private const NAMES = [
        'GOV' => ['group' => 'domains', 'fr' => 'Security, Compliance & Resilience Governance',
            'en' => 'Security, Compliance & Resilience Governance'],
        'AAT' => ['group' => 'domains', 'fr' => 'Artificial Intelligence & Autonomous Technologies',
            'en' => 'Artificial Intelligence & Autonomous Technologies'],
        'AST' => ['group' => 'domains', 'fr' => 'Asset Management', 'en' => 'Asset Management'],
        'BCD' => ['group' => 'domains', 'fr' => 'Business Continuity & Disaster Recovery',
            'en' => 'Business Continuity & Disaster Recovery'],
        'CAP' => ['group' => 'domains', 'fr' => 'Capacity & Performance Planning',
            'en' => 'Capacity & Performance Planning'],
        'CHG' => ['group' => 'domains', 'fr' => 'Change Management', 'en' => 'Change Management'],
        'CLD' => ['group' => 'domains', 'fr' => 'Cloud Security', 'en' => 'Cloud Security'],
        'CPL' => ['group' => 'domains', 'fr' => 'Compliance', 'en' => 'Compliance'],
        'CFG' => ['group' => 'domains', 'fr' => 'Configuration Management', 'en' => 'Configuration Management'],
        'MON' => ['group' => 'domains', 'fr' => 'Continuous Monitoring', 'en' => 'Continuous Monitoring'],
        'CRY' => ['group' => 'domains', 'fr' => 'Cryptographic Protections', 'en' => 'Cryptographic Protections'],
        'DCH' => ['group' => 'domains', 'fr' => 'Data Classification & Handling',
            'en' => 'Data Classification & Handling'],
        'PRI' => ['group' => 'domains', 'fr' => 'Data Privacy', 'en' => 'Data Privacy'],
        'EMB' => ['group' => 'domains', 'fr' => 'Embedded Technology', 'en' => 'Embedded Technology'],
        'END' => ['group' => 'domains', 'fr' => 'Endpoint Security', 'en' => 'Endpoint Security'],
        'HRS' => ['group' => 'domains', 'fr' => 'Human Resources Security', 'en' => 'Human Resources Security'],
        'IAC' => ['group' => 'domains', 'fr' => 'Identification & Authentication',
            'en' => 'Identification & Authentication'],
        'IRO' => ['group' => 'domains', 'fr' => 'Incident Response', 'en' => 'Incident Response'],
        'IAO' => ['group' => 'domains', 'fr' => 'Information Assurance', 'en' => 'Information Assurance'],
        'MNT' => ['group' => 'domains', 'fr' => 'Maintenance', 'en' => 'Maintenance'],
        'MDM' => ['group' => 'domains', 'fr' => 'Mobile Device Management', 'en' => 'Mobile Device Management'],
        'NET' => ['group' => 'domains', 'fr' => 'Network Security', 'en' => 'Network Security'],
        'PES' => ['group' => 'domains', 'fr' => 'Physical & Environmental Security',
            'en' => 'Physical & Environmental Security'],
        'PRM' => ['group' => 'domains', 'fr' => 'Project & Resource Management',
            'en' => 'Project & Resource Management'],
        'QTS' => ['group' => 'domains', 'fr' => 'Quantum Security', 'en' => 'Quantum Security'],
        'RSK' => ['group' => 'domains', 'fr' => 'Risk Management', 'en' => 'Risk Management'],
        'SEA' => ['group' => 'domains', 'fr' => 'Secure Engineering & Architecture',
            'en' => 'Secure Engineering & Architecture'],
        'SAT' => ['group' => 'domains', 'fr' => 'Security Awareness & Training',
            'en' => 'Security Awareness & Training'],
        'OPS' => ['group' => 'domains', 'fr' => 'Security Operations', 'en' => 'Security Operations'],
        'TDA' => ['group' => 'domains', 'fr' => 'Technology Development & Acquisition',
            'en' => 'Technology Development & Acquisition'],
        'TPM' => ['group' => 'domains', 'fr' => 'Third-Party Management', 'en' => 'Third-Party Management'],
        'THR' => ['group' => 'domains', 'fr' => 'Threat Management', 'en' => 'Threat Management'],
        'VPM' => ['group' => 'domains', 'fr' => 'Vulnerability & Patch Management',
            'en' => 'Vulnerability & Patch Management'],
        'WEB' => ['group' => 'domains', 'fr' => 'Web Security', 'en' => 'Web Security'],
    ];

    /**
     * Domaines du SCF reliés à une mesure de l'Annexe A ISO 27001.
     *
     * @return list<string>
     */
    public static function domainsForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::DOMAINS as $code => $domain) {
            if (in_array($annexACode, $domain['annex_a'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

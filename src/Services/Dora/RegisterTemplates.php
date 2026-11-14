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

namespace GlpiPlugin\Grcmanager\Services\Dora;

/**
 * Issue #540 : structure officielle du registre d'informations exigé par l'article 28,
 * paragraphe 3, du règlement (UE) 2022/2554 (DORA), telle que la fixe le règlement d'exécution
 * (UE) 2024/2956 de la Commission (normes techniques d'exécution, annexe I : modèles B_01.01 à
 * B_99.01 ; annexe III : types de services TIC S01 à S19). PHP pur, testé seul.
 *
 * Les codes de modèles et de colonnes, les intitulés des colonnes et les options des listes fermées
 * sont repris tels quels du texte officiel (version anglaise, JO L du 2.12.2024) : ce sont eux que
 * l'export écrit. Les explications affichées dans le plugin sont rédigées par ce projet.
 */
final class RegisterTemplates
{
    public const ITS_URL = 'https://eur-lex.europa.eu/eli/reg_impl/2024/2956/oj';
    public const DORA_URL = 'https://eur-lex.europa.eu/eli/reg/2022/2554/oj';

    /** Remplissage par le plugin : complet, partiel (une partie à la main) ou à faire à la main. */
    public const COVERAGE_AUTO = 'auto';
    public const COVERAGE_PARTIAL = 'partial';
    public const COVERAGE_MANUAL = 'manual';

    /** Date conventionnelle des modèles : sans fin, sans suppression, sans audit, non évaluée. */
    public const OPEN_DATE = '9999-12-31';

    /**
     * Modèles de l'annexe I : intitulé officiel, colonnes (code => intitulé officiel) et
     * remplissage par le plugin.
     *
     * @var array<string, array{name: string, coverage: string, columns: array<string, string>}>
     */
    public const TEMPLATES = [
        'B_01.01' => [
            'name'     => 'Financial entity maintaining the register of information',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'LEI of the financial entity maintaining the register of information',
                'c0020' => 'Name of the financial entity',
                'c0030' => 'Country of the financial entity',
                'c0040' => 'Type of financial entity',
                'c0050' => 'Competent authority',
                'c0060' => 'Date of the reporting',
            ],
        ],
        'B_01.02' => [
            'name'     => 'List of financial entities within the scope of the register of information',
            'coverage' => self::COVERAGE_PARTIAL,
            'columns'  => [
                'c0010' => 'LEI of the financial entity',
                'c0020' => 'Name of the financial entity',
                'c0030' => 'Country of the financial entity',
                'c0040' => 'Type of financial entity',
                'c0050' => 'Hierarchy of the financial entity within the group (where applicable)',
                'c0060' => 'LEI of the direct parent undertaking of the financial entity',
                'c0070' => 'Date of last update',
                'c0080' => 'Date of integration in the register of information',
                'c0090' => 'Date of deletion in the register of information',
                'c0100' => 'Currency',
                'c0110' => 'Value of total assets of the financial entity',
            ],
        ],
        'B_01.03' => [
            'name'     => 'List of branches',
            'coverage' => self::COVERAGE_MANUAL,
            'columns'  => [
                'c0010' => 'Identification code of the branch',
                'c0020' => 'LEI of the financial entity head office of the branch',
                'c0030' => 'Name of the branch',
                'c0040' => 'Country of the branch',
            ],
        ],
        'B_02.01' => [
            'name'     => 'Contractual arrangements – General information',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'Type of contractual arrangement',
                'c0030' => 'Overarching contractual arrangement reference number',
                'c0040' => 'Currency of the amount reported in B_02.01.0050',
                'c0050' => 'Annual expense or estimated cost of the contractual arrangement for the past year',
            ],
        ],
        'B_02.02' => [
            'name'     => 'Contractual arrangements – Specific information',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'LEI of the financial entity making use of the ICT service(s)',
                'c0030' => 'Identification code of the ICT third-party service provider',
                'c0040' => 'Type of code to identify the ICT third-party service provider',
                'c0050' => 'Function identifier',
                'c0060' => 'Type of ICT services',
                'c0070' => 'Start date of the contractual arrangement',
                'c0080' => 'End date of the contractual arrangement',
                'c0090' => 'Reason of the termination or ending of the contractual arrangement',
                'c0100' => 'Notice period for the financial entity making use of the ICT service(s)',
                'c0110' => 'Notice period for the ICT third-party service provider',
                'c0120' => 'Country of the governing law of the contractual arrangement',
                'c0130' => 'Country of provision of the ICT services',
                'c0140' => 'Storage of data',
                'c0150' => 'Location of the data at rest (storage)',
                'c0160' => 'Location of management of the data (processing)',
                'c0170' => 'Sensitiveness of the data stored by the ICT third-party service provider',
                'c0180' => 'Level of reliance on the ICT service supporting the critical or important function.',
            ],
        ],
        'B_02.03' => [
            'name'     => 'List of intra-group contractual arrangements',
            'coverage' => self::COVERAGE_MANUAL,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'Contractual arrangement linked to the contractual arrangement referred in B_02.03.0010',
            ],
        ],
        'B_03.01' => [
            'name'     => 'Entities signing the contractual arrangements for receiving ICT service(s) on behalf of '
                . 'the financial entities making use of the ICT service(s)',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'LEI of the entity signing the contractual arrangement',
            ],
        ],
        'B_03.02' => [
            'name'     => 'ICT third-party service providers signing the contractual arrangements for providing ICT '
                . 'service(s)',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'Identification code of ICT third-party service provider',
                'c0030' => 'Type of code to identify the ICT third-party service provider',
            ],
        ],
        'B_03.03' => [
            'name'     => 'Financial entities signing the contractual arrangements for providing ICT service(s) to '
                . 'other financial entities in the consolidation',
            'coverage' => self::COVERAGE_MANUAL,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'LEI of the financial entity providing ICT services',
            ],
        ],
        'B_04.01' => [
            'name'     => 'Financial entities making use of the ICT services',
            'coverage' => self::COVERAGE_PARTIAL,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'LEI of the financial entity making use of the ICT service(s)',
                'c0030' => 'Nature of the financial entity making use of the ICT service(s)',
                'c0040' => 'Identification code of the branch',
            ],
        ],
        'B_05.01' => [
            'name'     => 'ICT third-party service provider',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Identification code of ICT third-party service provider',
                'c0020' => 'Type of code to identify the ICT third-party service provider',
                'c0030' => 'Additional identification code of ICT third-party service provider',
                'c0040' => 'Type of additional identification code to identify the ICT third-party service provider',
                'c0050' => 'Legal name of the ICT third-party service provider',
                'c0060' => 'Name of the ICT third-party service provider in Latin alphabet',
                'c0070' => 'Type of person of the ICT third-party service provider',
                'c0080' => 'Country of the ICT third-party service provider’s headquarters',
                'c0090' => 'Currency of the amount reported in B_05.01.0070',
                'c0100' => 'Total annual expense or estimated cost of the ICT third-party service provider',
                'c0110' => 'Identification code of the ICT third-party service provider’s ultimate parent '
                    . 'undertaking',
                'c0120' => 'Type of code to identify the ICT third-party service provider’s ultimate parent '
                    . 'undertaking',
            ],
        ],
        'B_05.02' => [
            'name'     => 'ICT service supply chains',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'Type of ICT services',
                'c0030' => 'Identification code of the ICT third-party service provider',
                'c0040' => 'Type of code to identify the ICT third-party service provider',
                'c0050' => 'Rank',
                'c0060' => 'Identification code of the recipient of sub-contracted ICT services',
                'c0070' => 'Type of code to identify the recipient of sub-contracted ICT services',
            ],
        ],
        'B_06.01' => [
            'name'     => 'Functions identification',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Function Identifier',
                'c0020' => 'Licenced activity',
                'c0030' => 'Function name',
                'c0040' => 'LEI of the financial entity',
                'c0060' => 'Criticality or importance assessment',
                'c0070' => 'Reasons for criticality or importance',
                'c0080' => 'Date of the last assessment of criticality or importance',
                'c0090' => 'Recovery time objective of the function',
                'c0100' => 'Recovery point objective of the function',
                'c0110' => 'Impact of discontinuing the function',
            ],
        ],
        'B_07.01' => [
            'name'     => 'Assessment of the ICT services',
            'coverage' => self::COVERAGE_AUTO,
            'columns'  => [
                'c0010' => 'Contractual arrangement reference number',
                'c0020' => 'Identification code of the ICT third-party service provider',
                'c0030' => 'Type of code to identify the ICT third-party service provider',
                'c0040' => 'Type of ICT services',
                'c0050' => 'Substitutability of the ICT third-party service provider',
                'c0060' => 'Reason where the ICT third-party service provider is considered not substitutable or '
                    . 'difficult to be substitutable',
                'c0070' => 'Date of the last audit on the ICT third-party service provider',
                'c0080' => 'Existence of an exit plan',
                'c0090' => 'Possibility of reintegration of the contracted ICT service',
                'c0100' => 'Impact of discontinuing the ICT services',
                'c0110' => 'Are there alternative ICT third-party service providers identified?',
                'c0120' => 'Identification of alternative ICT TPP',
            ],
        ],
        'B_99.01' => [
            'name'     => 'Terminology used by financial entities using the ICT services',
            'coverage' => self::COVERAGE_PARTIAL,
            'columns'  => [
                'c0010' => 'Column Code',
                'c0020' => 'Column Name',
                'c0030' => 'Option',
                'c0040' => 'Description/Internal definition of the option',
            ],
        ],
    ];

    /** Annexe III : types de services TIC (identifiant => intitulé officiel). */
    public const SERVICE_TYPES = [
        'S01' => 'ICT project management',
        'S02' => 'ICT Development',
        'S03' => 'ICT help desk and first level support',
        'S04' => 'ICT security management services',
        'S05' => 'Provision of data',
        'S06' => 'Data analysis',
        'S07' => 'ICT, facilities and hosting services (excluding Cloud services)',
        'S08' => 'Computation',
        'S09' => 'Non-Cloud Data storage',
        'S10' => 'Telecom carrier',
        'S11' => 'Network infrastructure',
        'S12' => 'Hardware and physical devices',
        'S13' => 'Software licencing (excluding SaaS)',
        'S14' => 'ICT operation management (including maintenance)',
        'S15' => 'ICT Consulting',
        'S16' => 'ICT Risk management',
        'S17' => 'Cloud services: IaaS',
        'S18' => 'Cloud services: PaaS',
        'S19' => 'Cloud services: SaaS',
    ];

    /** B_01.01.0040 et B_01.02.0040 : type d'entité financière. */
    public const ENTITY_TYPES = [
        1  => 'credit institutions',
        2  => 'payment institutions, including payment institutions exempted pursuant to Directive (EU) 2015/2366',
        3  => 'account information service providers',
        4  => 'electronic money institutions, including electronic money institutions exempted pursuant to '
            . 'Directive 2009/110/EC',
        5  => 'investment firms',
        6  => 'crypto-asset service providers authorised under Regulation (EU) 2023/1114',
        7  => 'issuers of asset-referenced tokens authorised under Regulation (EU) 2023/1114',
        8  => 'central securities depositories',
        9  => 'central counterparties',
        10 => 'trading venues',
        11 => 'trade repositories',
        12 => 'managers of alternative investment funds',
        13 => 'management companies',
        14 => 'data reporting service providers',
        15 => 'insurance and reinsurance undertakings',
        16 => 'insurance intermediaries, reinsurance intermediaries and ancillary insurance intermediaries',
        17 => 'institutions for occupational retirement provision',
        18 => 'credit rating agencies',
        19 => 'administrators of critical benchmarks',
        20 => 'crowdfunding service providers',
        21 => 'securitisation repositories',
        22 => 'other financial entity',
    ];

    /** Dernier type d'entité financière au sens de l'article 2 (au-delà : « autre »). */
    public const LAST_FINANCIAL_TYPE = 21;

    /** B_01.02.0050 : place de l'entité dans le groupe. */
    public const HIERARCHIES = [
        1 => 'The financial entity is the ultimate parent undertaking in the consolidation',
        2 => 'The financial entity is the parent undertaking of a sub-consolidated part in the consolidation',
        3 => 'The financial entity is a subsidiary in the consolidation and is not a parent undertaking of a '
            . 'sub-consolidated part',
        4 => 'The financial entity is not part of a group',
        5 => 'The financial entity is a service provider to which the financial entity (or the third-party service '
            . 'provider acting on its behalf) is outsourcing all its operational activities',
    ];

    /** B_02.01.0020 : type d'accord contractuel. */
    public const ARRANGEMENT_TYPES = [
        1 => 'standalone arrangement',
        2 => 'overarching / master contractual arrangement',
        3 => 'subsequent or associated arrangement',
    ];
    public const ARRANGEMENT_SUBSEQUENT = 3;

    /** B_02.02.0090 : motif de la fin de l'accord (intitulé abrégé du texte officiel). */
    public const TERMINATION_REASONS = [
        1 => 'Termination not for cause',
        2 => 'Termination for cause: breach of applicable law, regulations or contractual provisions',
        3 => 'Termination for cause: impediments capable of altering the supported function',
        4 => 'Termination for cause: weaknesses regarding the management and security of sensitive data',
        5 => 'Termination following a request by a competent authority',
        6 => 'Other',
    ];

    /** B_02.02.0140 et B_07.01.0080 : oui ou non. */
    public const YES_NO = [1 => 'Yes', 2 => 'No'];
    public const YES = 1;

    /** B_02.02.0170 : sensibilité des données. */
    public const SENSITIVENESS = [1 => 'Low', 2 => 'Medium', 3 => 'High'];

    /** B_02.02.0180 : niveau de dépendance au service TIC. */
    public const RELIANCE = [
        1 => 'Not significant',
        2 => 'Low reliance',
        3 => 'Material reliance',
        4 => 'Full reliance',
    ];

    /** B_04.01.0030 : nature de l'entité qui utilise le service. */
    public const NATURES = [
        1 => 'The financial entity making use of the ICT service(s) is a branch of a financial entity',
        2 => 'The financial entity making use of the ICT service(s) is not a branch',
    ];
    public const NATURE_NOT_BRANCH = 2;

    /** B_05.01.0070 : type de personne du prestataire. */
    public const PERSON_TYPES = [
        1 => 'Legal person, excluding individuals acting in business capacity',
        2 => 'Individual acting in a business capacity',
    ];
    public const LEGAL_PERSON = 1;

    /** B_06.01.0060 : fonction critique ou importante. */
    public const CRITICALITY = [1 => 'Yes', 2 => 'No', 3 => 'Assessment not performed'];
    public const CRITICAL = 1;
    public const NOT_ASSESSED = 3;

    /** B_06.01.0110 et B_07.01.0100 : impact d'un arrêt. */
    public const IMPACTS = [1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Assessment not performed'];
    public const IMPACT_NOT_ASSESSED = 4;

    /** B_07.01.0050 : substituabilité du prestataire. */
    public const SUBSTITUTABILITY = [
        1 => 'Not substitutable',
        2 => 'Highly complex substitutability',
        3 => 'Medium complexity in terms of substitutability',
        4 => 'Easily substitutable',
    ];

    /** B_07.01.0060 : raison de la difficulté de substitution (intitulé abrégé du texte officiel). */
    public const NON_SUBSTITUTABLE_REASONS = [
        1 => 'The lack of real alternatives',
        2 => 'Difficulties in relation to a partial or full migration of the relevant data and workloads',
        3 => 'Both reasons referred to in points 1 and 2',
    ];

    /** B_07.01.0090 : réintégration possible du service. */
    public const REINTEGRATION = [1 => 'Easy', 2 => 'Difficult', 3 => 'Highly complex'];

    /** B_07.01.0110 : prestataires de remplacement identifiés (numérotation du texte officiel). */
    public const ALTERNATIVES = [1 => 'Yes', 2 => 'No', 7 => 'Assessment not performed'];
    public const ALTERNATIVES_YES = 1;

    /** B_05.01.0020 : codes d'identification d'un prestataire. */
    public const CODE_LEI = 'LEI';
    public const CODE_EUID = 'EUID';
    /** Codes nationaux, préfixés du pays d'émission (« FR_CRN »). */
    public const NATIONAL_CODES = ['CRN', 'VAT', 'PNR', 'NIN'];

    /**
     * B_99.01 : lignes prévues par le modèle (code de ligne => colonne, intitulé, option), dont
     * la définition interne reste à rédiger par l'entité.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const TERMINOLOGY_ROWS = [
        'R0010' => ['B_02.01.0020', 'Type of contractual arrangement', '1. Standalone arrangement'],
        'R0020' => ['B_02.01.0020', 'Type of contractual arrangement', '2. Overarching arrangement'],
        'R0030' => ['B_02.01.0020', 'Type of contractual arrangement', '3. Subsequent or associated arrangement'],
        'R0040' => ['B_02.02.0170', 'Sensitiveness of the data stored by the ICT third-party service provider', '1. Low'],
        'R0050' => ['B_02.02.0170', 'Sensitiveness of the data stored by the ICT third-party service provider', '2. Medium'],
        'R0060' => ['B_02.02.0170', 'Sensitiveness of the data stored by the ICT third-party service provider', '3. High'],
        'R0070' => ['B_06.01.0110', 'Impact of discontinuing the function', '1. Low'],
        'R0080' => ['B_06.01.0110', 'Impact of discontinuing the function', '2. Medium'],
        'R0090' => ['B_06.01.0110', 'Impact of discontinuing the function', '3. High'],
        'R0100' => ['B_07.01.0050', 'Substitutability of the ICT third-party service provider', '1. Not substitutable'],
        'R0110' => [
            'B_07.01.0050',
            'Substitutability of the ICT third-party service provider',
            '2. Highly complex substitutability',
        ],
        'R0120' => [
            'B_07.01.0050',
            'Substitutability of the ICT third-party service provider',
            '3. Medium complexity in terms of substitutability',
        ],
        'R0130' => ['B_07.01.0050', 'Substitutability of the ICT third-party service provider', '4. Easily substitutable'],
        'R0140' => ['B_07.01.0090', 'Possibility of reintegration of the contracted ICT service', '1. Easy'],
        'R0150' => ['B_07.01.0090', 'Possibility of reintegration of the contracted ICT service', '2. Difficult'],
        'R0160' => ['B_07.01.0090', 'Possibility of reintegration of the contracted ICT service', '3. Highly complex'],
        'R0170' => ['B_07.01.0100', 'Impact of discontinuing the ICT services', '1. Low'],
        'R0180' => ['B_07.01.0100', 'Impact of discontinuing the ICT services', '2. Medium'],
        'R0190' => ['B_07.01.0100', 'Impact of discontinuing the ICT services', '3. High'],
    ];

    /** Codes de pays ISO 3166-1 alpha-2 attribués. */
    public const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ '
        . 'BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG '
        . 'EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU '
        . 'ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV '
        . 'LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU '
        . 'NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN '
        . 'SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI '
        . 'VN VU WF WS YE YT ZA ZM ZW';

    /**
     * Nom de fichier d'un modèle dans l'export (« b_02.02.csv »), celui des paquets xBRL-CSV.
     */
    public static function fileName(string $template): string
    {
        return strtolower($template) . '.csv';
    }

    /**
     * Référence complète d'une colonne (« B_02.02.0050 »), comme la cite le texte officiel.
     */
    public static function columnReference(string $template, string $column): string
    {
        return $template . '.' . substr($column, 1);
    }
}

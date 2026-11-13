<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #379 : règlement (UE) 2022/2554 dit « DORA » (résilience opérationnelle numérique du secteur
 * financier). Exigences principales des chapitres II (gestion du risque lié aux TIC, articles 5 à 14),
 * III (incidents liés aux TIC, articles 17 à 19), IV (tests de résilience, articles 24 à 26),
 * V (risque lié aux prestataires tiers de services TIC, articles 28 et 30) et VI (partage
 * d'informations, article 45).
 *
 * Droits : texte législatif de l'Union publié sur EUR-Lex, réutilisation autorisée sous réserve de
 * mentionner la source (décision 2011/833/UE de la Commission, « © Union européenne,
 * https://eur-lex.europa.eu/ »). Les intitulés d'articles sont ceux des versions officielles française,
 * anglaise et italienne (CELEX 32022R2554, JO L 333 du 27.12.2022 ; le tiret de l'intitulé italien de
 * l'article 12 est écrit en trait d'union) ; les exigences sont résumées par ce projet en français et
 * en anglais (un utilisateur italien les lit en anglais, issue #582)
 * (`summary` = true), les articles étant trop longs pour être recopiés. Les délais de notification des
 * incidents majeurs viennent des normes techniques de réglementation prises en application de
 * l'article 20.
 *
 * Correspondance `annex_a` : indicative, établie par ce projet (aucune correspondance officielle
 * DORA ↔ ISO/IEC 27001 n'est publiée), d'où son affichage avec cette réserve.
 */
final class DoraCatalogDefaults
{
    public const SOURCE_URL = 'https://eur-lex.europa.eu/eli/reg/2022/2554/oj';

    /**
     * @var array<int, array{fr: string, en: string, it: string}> Article => intitulé officiel.
     */
    public const ARTICLES = [
        '5' => [
            'fr' => 'Gouvernance et organisation',
            'en' => 'Governance and organisation',
            'it' => 'Governance e organizzazione',
        ],
        '6' => [
            'fr' => 'Cadre de gestion du risque lié aux TIC',
            'en' => 'ICT risk management framework',
            'it' => 'Quadro per la gestione dei rischi informatici',
        ],
        '7' => [
            'fr' => 'Systèmes, protocoles et outils de TIC',
            'en' => 'ICT systems, protocols and tools',
            'it' => 'Sistemi, protocolli e strumenti di TIC',
        ],
        '8'  => ['fr' => 'Identification', 'en' => 'Identification', 'it' => 'Identificazione'],
        '9' => [
            'fr' => 'Protection et prévention',
            'en' => 'Protection and prevention',
            'it' => 'Protezione e prevenzione',
        ],
        '10' => ['fr' => 'Détection', 'en' => 'Detection', 'it' => 'Individuazione'],
        '11' => ['fr' => 'Réponse et rétablissement', 'en' => 'Response and recovery', 'it' => 'Risposta e ripristino'],
        '12' => [
            'fr' => 'Politiques et procédures de sauvegarde, procédures et méthodes de restauration et de '
                . 'rétablissement',
            'en' => 'Backup policies and procedures, restoration and recovery procedures and methods',
            'it' => 'Politiche e procedure di backup - Procedure e metodi di ripristino e recupero',
        ],
        '13' => [
            'fr' => 'Apprentissage et évolution',
            'en' => 'Learning and evolving',
            'it' => 'Apprendimento ed evoluzione',
        ],
        '14' => ['fr' => 'Communication', 'en' => 'Communication', 'it' => 'Comunicazione'],
        '17' => [
            'fr' => 'Processus de gestion des incidents liés aux TIC',
            'en' => 'ICT-related incident management process',
            'it' => 'Processo di gestione degli incidenti connessi alle TIC',
        ],
        '18' => [
            'fr' => 'Classification des incidents liés aux TIC et des cybermenaces',
            'en' => 'Classification of ICT-related incidents and cyber threats',
            'it' => 'Classificazione degli incidenti connessi alle TIC e delle minacce informatiche',
        ],
        '19' => [
            'fr' => 'Notification des incidents majeurs liés aux TIC et notification volontaire des '
                . 'cybermenaces importantes',
            'en' => 'Reporting of major ICT-related incidents and voluntary notification of significant '
                . 'cyber threats',
            'it' => 'Segnalazione dei gravi incidenti TIC e notifica volontaria delle minacce informatiche '
                . 'significative',
        ],
        '24' => [
            'fr' => 'Exigences générales applicables à la réalisation des tests de résilience opérationnelle '
                . 'numérique',
            'en' => 'General requirements for the performance of digital operational resilience testing',
            'it' => 'Requisiti generali per lo svolgimento dei test di resilienza operativa digitale',
        ],
        '25' => [
            'fr' => 'Tests des outils et systèmes de TIC',
            'en' => 'Testing of ICT tools and systems',
            'it' => 'Test di strumenti e sistemi di TIC',
        ],
        '26' => [
            'fr' => 'Tests avancés d\'outils, de systèmes et de processus de TIC basés sur des tests de '
                . 'pénétration fondés sur la menace',
            'en' => 'Advanced testing of ICT tools, systems and processes based on TLPT',
            'it' => 'Test avanzati di strumenti, sistemi e processi di TIC basati su test di penetrazione guidati '
                . 'dalla minaccia (TLPT)',
        ],
        '28' => ['fr' => 'Principes généraux', 'en' => 'General principles', 'it' => 'Principi generali'],
        '30' => [
            'fr' => 'Principales dispositions contractuelles',
            'en' => 'Key contractual provisions',
            'it' => 'Principali disposizioni contrattuali',
        ],
        '45' => [
            'fr' => 'Dispositifs de partage d\'informations et de renseignements sur les cybermenaces',
            'en' => 'Information-sharing arrangements on cyber threat information and intelligence',
            'it' => 'Meccanismi di condivisione delle informazioni e delle analisi delle minacce informatiche',
        ],
    ];

    /**
     * Clés : article seul (« 19 ») ou article et paragraphe (« 28.3 ») ; un article seul est un entier en PHP.
     *
     * @var array<int|string, array{article: string, summary: bool, fr: string, en: string, annex_a: list<string>}>
     */
    public const REQUIREMENTS = [
        '5.2' => [
            'article' => '5',
            'summary' => true,
            'fr'      => 'L\'organe de direction définit, approuve, supervise le cadre de gestion du risque lié aux '
                . 'TIC et en est responsable : rôles et responsabilités, stratégie de résilience, budget et '
                . 'formation de ses membres.',
            'en'      => 'The management body defines, approves, oversees and is accountable for the ICT risk '
                . 'management framework: roles and responsibilities, resilience strategy, budget and training of '
                . 'its members.',
            'annex_a' => ['A.5.1', 'A.5.2', 'A.5.4', 'A.6.3'],
        ],
        '6.1' => [
            'article' => '6',
            'summary' => true,
            'fr'      => 'Un cadre de gestion du risque lié aux TIC solide, complet et documenté : stratégies, '
                . 'politiques, procédures, protocoles et outils pour protéger les actifs informationnels et TIC.',
            'en'      => 'A sound, comprehensive and well-documented ICT risk management framework: strategies, '
                . 'policies, procedures, protocols and tools to protect information and ICT assets.',
            'annex_a' => ['A.5.1', 'A.5.37'],
        ],
        '6.5' => [
            'article' => '6',
            'summary' => true,
            'fr'      => 'Le cadre est documenté et réexaminé au moins une fois par an, ainsi qu\'après un incident '
                . 'majeur lié aux TIC ou sur instruction de l\'autorité de surveillance.',
            'en'      => 'The framework is documented and reviewed at least once a year, as well as after a major '
                . 'ICT-related incident or following supervisory instructions.',
            'annex_a' => ['A.5.1', 'A.5.35'],
        ],
        '6.6' => [
            'article' => '6',
            'summary' => true,
            'fr'      => 'Le cadre fait l\'objet d\'un audit interne régulier par des auditeurs disposant de '
                . 'connaissances suffisantes, avec un suivi des constatations.',
            'en'      => 'The framework is subject to regular internal audit by auditors with sufficient knowledge, '
                . 'with a follow-up of the findings.',
            'annex_a' => ['A.5.35', 'A.5.36'],
        ],
        '6.8' => [
            'article' => '6',
            'summary' => true,
            'fr'      => 'Une stratégie de résilience opérationnelle numérique : objectifs de sécurité de '
                . 'l\'information, tolérance au risque lié aux TIC, architecture de référence.',
            'en'      => 'A digital operational resilience strategy: information security objectives, ICT risk '
                . 'tolerance, reference architecture.',
            'annex_a' => ['A.5.1', 'A.5.29'],
        ],
        '7' => [
            'article' => '7',
            'summary' => true,
            'fr'      => 'Des systèmes, protocoles et outils de TIC appropriés, fiables, dotés d\'une capacité '
                . 'suffisante et résilients sur le plan technologique, tenus à jour.',
            'en'      => 'Appropriate, reliable ICT systems, protocols and tools, with sufficient capacity and '
                . 'technological resilience, kept up to date.',
            'annex_a' => ['A.8.6', 'A.8.14'],
        ],
        '8.1' => [
            'article' => '8',
            'summary' => true,
            'fr'      => 'Identifier, classer et documenter les fonctions métier, les rôles et les actifs '
                . 'informationnels et TIC qui en dépendent, et en tenir un inventaire à jour.',
            'en'      => 'Identify, classify and document business functions, roles and the information and ICT '
                . 'assets supporting them, and keep an up-to-date inventory.',
            'annex_a' => ['A.5.9', 'A.5.12'],
        ],
        '8.2' => [
            'article' => '8',
            'summary' => true,
            'fr'      => 'Identifier en continu les sources de risque lié aux TIC, en particulier les cybermenaces et '
                . 'les vulnérabilités, et évaluer régulièrement les risques.',
            'en'      => 'Continuously identify sources of ICT risk, in particular cyber threats and '
                . 'vulnerabilities, and regularly assess the risks.',
            'annex_a' => ['A.5.7', 'A.8.8'],
        ],
        '8.6' => [
            'article' => '8',
            'summary' => true,
            'fr'      => 'Tenir à jour l\'inventaire des processus qui dépendent de prestataires tiers de services '
                . 'TIC et de leurs interconnexions.',
            'en'      => 'Maintain the inventory of processes that depend on ICT third-party service providers and '
                . 'of their interconnections.',
            'annex_a' => ['A.5.19', 'A.5.22'],
        ],
        '9.2' => [
            'article' => '9',
            'summary' => true,
            'fr'      => 'Assurer la sécurité des moyens de transfert des données et garantir la disponibilité, '
                . 'l\'authenticité, l\'intégrité et la confidentialité des données.',
            'en'      => 'Ensure the security of the means of data transfer and the availability, authenticity, '
                . 'integrity and confidentiality of data.',
            'annex_a' => ['A.5.14', 'A.8.20', 'A.8.24'],
        ],
        '9.4' => [
            'article' => '9',
            'summary' => true,
            'fr'      => 'Politiques de sécurité, de contrôle d\'accès et d\'authentification forte, de cryptographie, '
                . 'de gestion des changements et des correctifs.',
            'en'      => 'Policies on security, access control and strong authentication, cryptography, change '
                . 'management and patching.',
            'annex_a' => ['A.5.15', 'A.8.5', 'A.8.24', 'A.8.32', 'A.8.8'],
        ],
        '10' => [
            'article' => '10',
            'summary' => true,
            'fr'      => 'Des mécanismes de détection rapide des activités anormales, des problèmes de performance '
                . 'des réseaux et des incidents liés aux TIC, avec des seuils d\'alerte.',
            'en'      => 'Mechanisms to promptly detect anomalous activities, network performance issues and '
                . 'ICT-related incidents, with alert thresholds.',
            'annex_a' => ['A.8.15', 'A.8.16'],
        ],
        '11' => [
            'article' => '11',
            'summary' => true,
            'fr'      => 'Une politique de continuité des activités de TIC, des plans de réponse et de '
                . 'rétablissement, testés régulièrement, et une analyse d\'impact sur les activités.',
            'en'      => 'An ICT business continuity policy, response and recovery plans tested regularly, and a '
                . 'business impact analysis.',
            'annex_a' => ['A.5.29', 'A.5.30', 'A.5.26'],
        ],
        '12' => [
            'article' => '12',
            'summary' => true,
            'fr'      => 'Des politiques de sauvegarde, des procédures de restauration testées et des systèmes de TIC '
                . 'redondants, séparés des systèmes sources.',
            'en'      => 'Backup policies, tested restoration procedures and redundant ICT systems, segregated from '
                . 'the source systems.',
            'annex_a' => ['A.8.13', 'A.8.14'],
        ],
        '13' => [
            'article' => '13',
            'summary' => true,
            'fr'      => 'Tirer les leçons des incidents et des tests, assurer une veille sur les menaces et former '
                . 'le personnel et l\'organe de direction à la sécurité des TIC.',
            'en'      => 'Learn from incidents and tests, monitor threats and train staff and the management body '
                . 'in ICT security.',
            'annex_a' => ['A.5.27', 'A.5.7', 'A.6.3'],
        ],
        '14' => [
            'article' => '14',
            'summary' => true,
            'fr'      => 'Des plans de communication de crise pour informer clients, contreparties et public des '
                . 'incidents majeurs liés aux TIC.',
            'en'      => 'Crisis communication plans to inform clients, counterparts and the public about major '
                . 'ICT-related incidents.',
            'annex_a' => ['A.5.24', 'A.5.26'],
        ],
        '17' => [
            'article' => '17',
            'summary' => true,
            'fr'      => 'Un processus de gestion des incidents liés aux TIC : détection, enregistrement, '
                . 'classification, traitement, remontée à la direction et analyse des causes.',
            'en'      => 'An ICT-related incident management process: detection, logging, classification, '
                . 'handling, escalation to management and root cause analysis.',
            'annex_a' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.6.8'],
        ],
        '18' => [
            'article' => '18',
            'summary' => true,
            'fr'      => 'Classer les incidents selon les clients touchés, la durée, l\'étendue géographique, les '
                . 'pertes de données, la criticité des services et l\'impact économique.',
            'en'      => 'Classify incidents by clients affected, duration, geographical spread, data losses, '
                . 'criticality of services and economic impact.',
            'annex_a' => ['A.5.25'],
        ],
        '19' => [
            'article' => '19',
            'summary' => true,
            'fr'      => 'Notifier les incidents majeurs à l\'autorité compétente : notification initiale dans les 4 '
                . 'heures suivant leur classification et au plus tard 24 heures après leur détection, rapport '
                . 'intermédiaire sous 72 heures, rapport final dans un délai d\'un mois.',
            'en'      => 'Report major incidents to the competent authority: initial notification within 4 hours of '
                . 'classification and no later than 24 hours after detection, intermediate report within 72 hours, '
                . 'final report within one month.',
            'annex_a' => ['A.5.5', 'A.5.26'],
        ],
        '24' => [
            'article' => '24',
            'summary' => true,
            'fr'      => 'Un programme de tests de résilience opérationnelle numérique, fondé sur les risques, mené '
                . 'par des parties indépendantes, avec la correction des faiblesses constatées.',
            'en'      => 'A risk-based digital operational resilience testing programme, carried out by independent '
                . 'parties, with remediation of the weaknesses found.',
            'annex_a' => ['A.5.35', 'A.8.8'],
        ],
        '25' => [
            'article' => '25',
            'summary' => true,
            'fr'      => 'Tester au moins une fois par an les systèmes qui soutiennent des fonctions critiques : '
                . 'évaluations des vulnérabilités, analyses de sécurité des réseaux, tests de pénétration.',
            'en'      => 'Test at least yearly the systems supporting critical functions: vulnerability '
                . 'assessments, network security assessments, penetration tests.',
            'annex_a' => ['A.8.8', 'A.8.29'],
        ],
        '26' => [
            'article' => '26',
            'summary' => true,
            'fr'      => 'Pour les entités désignées, des tests de pénétration fondés sur la menace (TLPT) au moins '
                . 'tous les trois ans, sur les systèmes de production.',
            'en'      => 'For designated entities, threat-led penetration testing (TLPT) at least every three years, '
                . 'on live production systems.',
            'annex_a' => ['A.8.8', 'A.5.7'],
        ],
        '28.3' => [
            'article' => '28',
            'summary' => true,
            'fr'      => 'Tenir un registre d\'informations de tous les accords contractuels avec des prestataires '
                . 'tiers de services TIC, communiqué à l\'autorité sur demande.',
            'en'      => 'Maintain a register of information on all contractual arrangements with ICT third-party '
                . 'service providers, provided to the authority on request.',
            'annex_a' => ['A.5.19', 'A.5.20'],
        ],
        '28.4' => [
            'article' => '28',
            'summary' => true,
            'fr'      => 'Avant de conclure un accord, évaluer le prestataire, les risques de concentration et sa '
                . 'capacité à respecter les exigences de sécurité.',
            'en'      => 'Before entering into an arrangement, assess the provider, concentration risks and its '
                . 'ability to meet security requirements.',
            'annex_a' => ['A.5.19', 'A.5.21'],
        ],
        '28.8' => [
            'article' => '28',
            'summary' => true,
            'fr'      => 'Prévoir des stratégies de sortie pour les services TIC qui soutiennent des fonctions '
                . 'critiques, testées et documentées.',
            'en'      => 'Put in place documented and tested exit strategies for ICT services supporting critical '
                . 'functions.',
            'annex_a' => ['A.5.19', 'A.5.30'],
        ],
        '30' => [
            'article' => '30',
            'summary' => true,
            'fr'      => 'Des clauses contractuelles minimales : description des services, lieux de traitement, '
                . 'sécurité des données, assistance en cas d\'incident, droits d\'accès et d\'audit, résiliation.',
            'en'      => 'Minimum contractual provisions: description of services, processing locations, data '
                . 'security, assistance during incidents, access and audit rights, termination.',
            'annex_a' => ['A.5.20', 'A.5.21', 'A.5.22'],
        ],
        '45' => [
            'article' => '45',
            'summary' => true,
            'fr'      => 'Possibilité d\'échanger entre entités financières des informations et renseignements sur '
                . 'les cybermenaces, dans un cadre de confiance.',
            'en'      => 'Financial entities may exchange cyber threat information and intelligence among '
                . 'themselves, within trusted communities.',
            'annex_a' => ['A.5.6', 'A.5.7'],
        ],
    ];

    /**
     * Correspondance inverse : contrôle Annexe A => exigences DORA qui y renvoient.
     *
     * @return list<string>
     */
    public static function requirementsForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::REQUIREMENTS as $code => $requirement) {
            if (in_array($annexACode, $requirement['annex_a'], true)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /** Libellé affichable d'une exigence, ex. « Art. 28 §3 » ou « Art. 19 ». */
    public static function reference(string $code): string
    {
        $parts = explode('.', $code);

        return 'Art. ' . $parts[0] . (isset($parts[1]) ? ' §' . $parts[1] : '');
    }
}

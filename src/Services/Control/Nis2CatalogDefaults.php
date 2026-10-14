<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #110 : directive (UE) 2022/2555 dite « NIS2 », exigences de gestion des risques
 * applicables aux entités essentielles et importantes — article 20 (gouvernance), article 21 §2
 * (dix mesures minimales, points a à j) et article 23 §4 (étapes de notification des incidents
 * importants).
 *
 * Droits : texte législatif de l'Union publié sur EUR-Lex, réutilisation autorisée sous réserve
 * de mentionner la source (décision 2011/833/UE de la Commission, « © Union européenne,
 * https://eur-lex.europa.eu/ »). Les points de l'article 21 §2 sont recopiés tels quels dans les
 * versions linguistiques officielles française et anglaise (CELEX 32022L2555, JO L 333 du
 * 27.12.2022) ; les articles 20 et 23, plus longs, sont résumés (`text` reste alors fidèle au
 * texte mais abrégé, signalé par `summary` = true). Mention de source affichée sous la carte NIS2
 * de templates/referentiels.html.twig et dans le README.
 *
 * Correspondance `annex_a` : indicative, établie par ce projet (aucune correspondance officielle
 * NIS2 ↔ ISO/IEC 27001 n'est publiée par la Commission), d'où son affichage avec cette réserve.
 */
final class Nis2CatalogDefaults
{
    public const SOURCE_URL = 'https://eur-lex.europa.eu/eli/dir/2022/2555/oj';

    /**
     * @var array<int, array{fr: string, en: string}> Article => intitulé officiel (clés numériques,
     * donc int en PHP).
     */
    public const ARTICLES = [
        '20' => ['fr' => 'Gouvernance', 'en' => 'Governance'],
        '21' => [
            'fr' => 'Mesures de gestion des risques en matière de cybersécurité',
            'en' => 'Cybersecurity risk-management measures',
        ],
        '23' => ['fr' => 'Obligations d\'information', 'en' => 'Reporting obligations'],
    ];

    /**
     * @var array<string, array{article: string, summary: bool, fr: string, en: string, annex_a: list<string>}>
     */
    public const REQUIREMENTS = [
        '20.1' => [
            'article' => '20',
            'summary' => true,
            'fr'      => 'Les organes de direction approuvent les mesures de gestion des risques en matière de '
                . 'cybersécurité, supervisent leur mise en œuvre et peuvent être tenus responsables de leur '
                . 'violation.',
            'en'      => 'Management bodies approve the cybersecurity risk-management measures, oversee their '
                . 'implementation and can be held liable for infringements.',
            'annex_a' => ['A.5.1', 'A.5.2', 'A.5.4'],
        ],
        '20.2' => [
            'article' => '20',
            'summary' => true,
            'fr'      => 'Les membres des organes de direction suivent une formation ; une formation similaire est '
                . 'encouragée pour le personnel.',
            'en'      => 'Members of the management bodies follow training; similar training is encouraged for '
                . 'employees.',
            'annex_a' => ['A.6.3'],
        ],
        '21.2.a' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'les politiques relatives à l\'analyse des risques et à la sécurité des systèmes '
                . 'd\'information',
            'en'      => 'policies on risk analysis and information system security',
            'annex_a' => ['A.5.1', 'A.5.7'],
        ],
        '21.2.b' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'la gestion des incidents',
            'en'      => 'incident handling',
            'annex_a' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8'],
        ],
        '21.2.c' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'la continuité des activités, par exemple la gestion des sauvegardes et la reprise des '
                . 'activités, et la gestion des crises',
            'en'      => 'business continuity, such as backup management and disaster recovery, and crisis '
                . 'management',
            'annex_a' => ['A.5.29', 'A.5.30', 'A.8.13', 'A.8.14'],
        ],
        '21.2.d' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'la sécurité de la chaîne d\'approvisionnement, y compris les aspects liés à la sécurité '
                . 'concernant les relations entre chaque entité et ses fournisseurs ou prestataires '
                . 'de services directs',
            'en'      => 'supply chain security, including security-related aspects concerning the relationships '
                . 'between each entity and its direct suppliers or service providers',
            'annex_a' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
        ],
        '21.2.e' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'la sécurité de l\'acquisition, du développement et de la maintenance des réseaux et des '
                . 'systèmes d\'information, y compris le traitement et la divulgation des vulnérabilités',
            'en'      => 'security in network and information systems acquisition, development and maintenance, '
                . 'including vulnerability handling and disclosure',
            'annex_a' => ['A.8.8', 'A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.32'],
        ],
        '21.2.f' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'des politiques et des procédures pour évaluer l\'efficacité des mesures de gestion des '
                . 'risques en matière de cybersécurité',
            'en'      => 'policies and procedures to assess the effectiveness of cybersecurity risk-management '
                . 'measures',
            'annex_a' => ['A.5.35', 'A.5.36'],
        ],
        '21.2.g' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'les pratiques de base en matière de cyberhygiène et la formation à la cybersécurité',
            'en'      => 'basic cyber hygiene practices and cybersecurity training',
            'annex_a' => ['A.6.3', 'A.8.1', 'A.8.7', 'A.8.9'],
        ],
        '21.2.h' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'des politiques et des procédures relatives à l\'utilisation de la cryptographie et, le '
                . 'cas échéant, du chiffrement',
            'en'      => 'policies and procedures regarding the use of cryptography and, where appropriate, '
                . 'encryption',
            'annex_a' => ['A.8.24'],
        ],
        '21.2.i' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'la sécurité des ressources humaines, des politiques de contrôle d\'accès et la gestion '
                . 'des actifs',
            'en'      => 'human resources security, access control policies and asset management',
            'annex_a' => ['A.6.1', 'A.6.2', 'A.6.5', 'A.5.9', 'A.5.10', 'A.5.11', 'A.5.15', 'A.5.18'],
        ],
        '21.2.j' => [
            'article' => '21',
            'summary' => false,
            'fr'      => 'l\'utilisation de solutions d\'authentification à plusieurs facteurs ou '
                . 'd\'authentification continue, de communications vocales, vidéo et textuelles sécurisées et de '
                . 'systèmes sécurisés de communication d\'urgence au sein de l\'entité, selon les besoins',
            'en'      => 'the use of multi-factor authentication or continuous authentication solutions, secured '
                . 'voice, video and text communications and secured emergency communication systems within the '
                . 'entity, where appropriate',
            'annex_a' => ['A.8.5', 'A.5.14'],
        ],
        '23.4.a' => [
            'article' => '23',
            'summary' => true,
            'fr'      => 'Alerte précoce au CSIRT ou à l\'autorité compétente dans les 24 heures après avoir eu '
                . 'connaissance d\'un incident important.',
            'en'      => 'Early warning to the CSIRT or competent authority within 24 hours of becoming aware of a '
                . 'significant incident.',
            'annex_a' => ['A.5.5', 'A.5.24', 'A.5.26', 'A.6.8'],
        ],
        '23.4.b' => [
            'article' => '23',
            'summary' => true,
            'fr'      => 'Notification d\'incident dans les 72 heures, avec une évaluation initiale de la gravité, '
                . 'de l\'impact et des indicateurs de compromission.',
            'en'      => 'Incident notification within 72 hours, with an initial assessment of severity, impact '
                . 'and indicators of compromise.',
            'annex_a' => ['A.5.5', 'A.5.25', 'A.5.26'],
        ],
        '23.4.c' => [
            'article' => '23',
            'summary' => true,
            'fr'      => 'Rapport intermédiaire à la demande du CSIRT ou de l\'autorité compétente.',
            'en'      => 'Intermediate report upon request of the CSIRT or competent authority.',
            'annex_a' => ['A.5.5', 'A.5.26'],
        ],
        '23.4.d' => [
            'article' => '23',
            'summary' => true,
            'fr'      => 'Rapport final au plus tard un mois après la notification : description, cause profonde, '
                . 'mesures d\'atténuation, impact transfrontière éventuel.',
            'en'      => 'Final report no later than one month after the notification: description, root cause, '
                . 'mitigation measures, cross-border impact if any.',
            'annex_a' => ['A.5.5', 'A.5.27', 'A.5.28'],
        ],
    ];

    /**
     * Correspondance inverse : contrôle Annexe A => exigences NIS2 qui y renvoient.
     *
     * @return list<string>
     */
    public static function requirementsForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::REQUIREMENTS as $code => $requirement) {
            if (in_array($annexACode, $requirement['annex_a'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Libellé affichable d'une exigence, ex. « Art. 21 §2 j) » ou « Art. 23 §4 a) ». */
    public static function reference(string $code): string
    {
        $parts = explode('.', $code);

        return 'Art. ' . $parts[0] . ' §' . $parts[1] . (isset($parts[2]) ? ' ' . $parts[2] . ')' : '');
    }
}

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #111 : Guide d'hygiène informatique de l'ANSSI, « Renforcer la sécurité de son système
 * d'information en 42 mesures », version 2.0 de septembre 2017 — les 42 intitulés et les 10
 * thèmes sont reproduits tels qu'ils figurent dans l'« Outil de suivi » en fin de guide (pages
 * 60 à 65, y compris l'orthographe « Connaitre » du thème II).
 *
 * Droits : publié par l'ANSSI sous Licence Ouverte / Open Licence (Etalab V1) — réutilisation
 * libre, y compris commerciale, sous réserve de mentionner la paternité (ANSSI, titre, version et
 * date). Mention affichée sous la carte ANSSI de templates/referentiels.html.twig et dans le
 * README. La traduction anglaise (`en`) est indicative, établie par ce projet : l'ANSSI ne publie
 * pas de version anglaise des intitulés de cette version du guide.
 *
 * Correspondance `annex_a` : indicative, établie par ce projet (l'ANSSI ne publie pas de
 * correspondance avec ISO/IEC 27001:2022). La mesure 41 (analyse de risques formelle) relève de
 * l'article 6.1 de la norme et non de l'Annexe A, d'où sa liste vide.
 */
final class AnssiHygieneCatalogDefaults
{
    public const SOURCE_URL = 'https://cyber.gouv.fr/publications/guide-dhygiene-informatique';

    /** Mesure sans équivalent dans l'Annexe A (relève de l'article 6.1 de la norme). */
    public const RISK_ANALYSIS_MEASURE = 41;

    /** @var array<string, array{fr: string, en: string}> Thème (numéro romain) => intitulé. */
    public const THEMES = [
        'I'    => ['fr' => 'Sensibiliser et former', 'en' => 'Raise awareness and train'],
        'II'   => ['fr' => 'Connaitre le système d\'information', 'en' => 'Know the information system'],
        'III'  => ['fr' => 'Authentifier et contrôler les accès', 'en' => 'Authenticate and control access'],
        'IV'   => ['fr' => 'Sécuriser les postes', 'en' => 'Secure workstations'],
        'V'    => ['fr' => 'Sécuriser le réseau', 'en' => 'Secure the network'],
        'VI'   => ['fr' => 'Sécuriser l\'administration', 'en' => 'Secure administration'],
        'VII'  => ['fr' => 'Gérer le nomadisme', 'en' => 'Manage mobile working'],
        'VIII' => [
            'fr' => 'Maintenir à jour le système d\'information',
            'en' => 'Keep the information system up to date',
        ],
        'IX'   => ['fr' => 'Superviser, auditer, réagir', 'en' => 'Monitor, audit, respond'],
        'X'    => ['fr' => 'Pour aller plus loin', 'en' => 'Going further'],
    ];

    /**
     * @var array<int, array{theme: string, fr: string, en: string, annex_a: list<string>}>
     */
    public const MEASURES = [
        1  => [
            'theme'   => 'I',
            'fr'      => 'Former les équipes opérationnelles à la sécurité des systèmes d\'information',
            'en'      => 'Train operational teams in information systems security',
            'annex_a' => ['A.6.3'],
        ],
        2  => [
            'theme'   => 'I',
            'fr'      => 'Sensibiliser les utilisateurs aux bonnes pratiques élémentaires de sécurité informatique',
            'en'      => 'Make users aware of basic IT security best practices',
            'annex_a' => ['A.6.3'],
        ],
        3  => [
            'theme'   => 'I',
            'fr'      => 'Maîtriser les risques de l\'infogérance',
            'en'      => 'Control the risks of IT outsourcing',
            'annex_a' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22'],
        ],
        4  => [
            'theme'   => 'II',
            'fr'      => 'Identifier les informations et serveurs les plus sensibles et maintenir un schéma du '
                . 'réseau',
            'en'      => 'Identify the most sensitive information and servers and maintain a network diagram',
            'annex_a' => ['A.5.9', 'A.5.12', 'A.8.20'],
        ],
        5  => [
            'theme'   => 'II',
            'fr'      => 'Disposer d\'un inventaire exhaustif des comptes privilégiés et le maintenir à jour',
            'en'      => 'Keep a complete, up-to-date inventory of privileged accounts',
            'annex_a' => ['A.5.16', 'A.8.2'],
        ],
        6  => [
            'theme'   => 'II',
            'fr'      => 'Organiser les procédures d\'arrivée, de départ et de changement de fonction des '
                . 'utilisateurs',
            'en'      => 'Organise procedures for users joining, leaving and changing roles',
            'annex_a' => ['A.5.11', 'A.5.16', 'A.5.18', 'A.6.1', 'A.6.5'],
        ],
        7  => [
            'theme'   => 'II',
            'fr'      => 'Autoriser la connexion au réseau de l\'entité aux seuls équipements maîtrisés',
            'en'      => 'Allow only managed devices to connect to the organisation\'s network',
            'annex_a' => ['A.8.1', 'A.8.20'],
        ],
        8  => [
            'theme'   => 'III',
            'fr'      => 'Identifier nommément chaque personne accédant au système et distinguer les rôles '
                . 'utilisateur/administrateur',
            'en'      => 'Identify by name each person accessing the system and separate user and '
                . 'administrator roles',
            'annex_a' => ['A.5.16', 'A.8.2'],
        ],
        9  => [
            'theme'   => 'III',
            'fr'      => 'Attribuer les bons droits sur les ressources sensibles du système d\'information',
            'en'      => 'Grant the right permissions on sensitive information system resources',
            'annex_a' => ['A.5.15', 'A.5.18', 'A.8.3'],
        ],
        10 => [
            'theme'   => 'III',
            'fr'      => 'Définir et vérifier des règles de choix et de dimensionnement des mots de passe',
            'en'      => 'Define and enforce rules for choosing and sizing passwords',
            'annex_a' => ['A.5.17'],
        ],
        11 => [
            'theme'   => 'III',
            'fr'      => 'Protéger les mots de passe stockés sur les systèmes',
            'en'      => 'Protect passwords stored on systems',
            'annex_a' => ['A.5.17', 'A.8.24'],
        ],
        12 => [
            'theme'   => 'III',
            'fr'      => 'Changer les éléments d\'authentification par défaut sur les équipements et services',
            'en'      => 'Change default authentication credentials on devices and services',
            'annex_a' => ['A.5.17', 'A.8.9'],
        ],
        13 => [
            'theme'   => 'III',
            'fr'      => 'Privilégier lorsque c\'est possible une authentification forte',
            'en'      => 'Use strong authentication wherever possible',
            'annex_a' => ['A.8.5'],
        ],
        14 => [
            'theme'   => 'IV',
            'fr'      => 'Mettre en place un niveau de sécurité minimal sur l\'ensemble du parc informatique',
            'en'      => 'Apply a minimum security level across all IT equipment',
            'annex_a' => ['A.8.1', 'A.8.7', 'A.8.9'],
        ],
        15 => [
            'theme'   => 'IV',
            'fr'      => 'Se protéger des menaces relatives à l\'utilisation de supports amovibles',
            'en'      => 'Protect against threats related to removable media',
            'annex_a' => ['A.7.10', 'A.8.7'],
        ],
        16 => [
            'theme'   => 'IV',
            'fr'      => 'Utiliser un outil de gestion centralisée afin d\'homogénéiser les politiques de '
                . 'sécurité',
            'en'      => 'Use a centralised management tool to harmonise security policies',
            'annex_a' => ['A.8.9'],
        ],
        17 => [
            'theme'   => 'IV',
            'fr'      => 'Activer et configurer le pare-feu local des postes de travail',
            'en'      => 'Enable and configure the local firewall on workstations',
            'annex_a' => ['A.8.1', 'A.8.20'],
        ],
        18 => [
            'theme'   => 'IV',
            'fr'      => 'Chiffrer les données sensibles transmises par voie Internet',
            'en'      => 'Encrypt sensitive data sent over the Internet',
            'annex_a' => ['A.5.14', 'A.8.24'],
        ],
        19 => [
            'theme'   => 'V',
            'fr'      => 'Segmenter le réseau et mettre en place un cloisonnement entre ces zones',
            'en'      => 'Segment the network and isolate the resulting zones',
            'annex_a' => ['A.8.22'],
        ],
        20 => [
            'theme'   => 'V',
            'fr'      => 'S\'assurer de la sécurité des réseaux d\'accès Wi-Fi et de la séparation des usages',
            'en'      => 'Secure Wi-Fi access networks and separate their uses',
            'annex_a' => ['A.8.20', 'A.8.21'],
        ],
        21 => [
            'theme'   => 'V',
            'fr'      => 'Utiliser des protocoles sécurisés dès qu\'ils existent',
            'en'      => 'Use secure protocols whenever they exist',
            'annex_a' => ['A.8.21', 'A.8.24'],
        ],
        22 => [
            'theme'   => 'V',
            'fr'      => 'Mettre en place une passerelle d\'accès sécurisé à Internet',
            'en'      => 'Set up a secure Internet access gateway',
            'annex_a' => ['A.8.20', 'A.8.23'],
        ],
        23 => [
            'theme'   => 'V',
            'fr'      => 'Cloisonner les services visibles depuis Internet du reste du système d\'information',
            'en'      => 'Isolate Internet-facing services from the rest of the information system',
            'annex_a' => ['A.8.22'],
        ],
        24 => [
            'theme'   => 'V',
            'fr'      => 'Protéger sa messagerie professionnelle',
            'en'      => 'Protect business email',
            'annex_a' => ['A.5.14', 'A.8.21'],
        ],
        25 => [
            'theme'   => 'V',
            'fr'      => 'Sécuriser les interconnexions réseau dédiées avec les partenaires',
            'en'      => 'Secure dedicated network interconnections with partners',
            'annex_a' => ['A.5.14', 'A.8.21'],
        ],
        26 => [
            'theme'   => 'V',
            'fr'      => 'Contrôler et protéger l\'accès aux salles serveurs et aux locaux techniques',
            'en'      => 'Control and protect access to server rooms and technical premises',
            'annex_a' => ['A.7.1', 'A.7.2', 'A.7.3'],
        ],
        27 => [
            'theme'   => 'VI',
            'fr'      => 'Interdire l\'accès à Internet depuis les postes ou serveurs utilisés pour '
                . 'l\'administration du système d\'information',
            'en'      => 'Block Internet access from workstations or servers used to administer the information '
                . 'system',
            'annex_a' => ['A.8.2', 'A.8.22'],
        ],
        28 => [
            'theme'   => 'VI',
            'fr'      => 'Utiliser un réseau dédié et cloisonné pour l\'administration du système d\'information',
            'en'      => 'Use a dedicated, isolated network to administer the information system',
            'annex_a' => ['A.8.2', 'A.8.22'],
        ],
        29 => [
            'theme'   => 'VI',
            'fr'      => 'Limiter au strict besoin opérationnel les droits d\'administration sur les postes de '
                . 'travail',
            'en'      => 'Limit administration rights on workstations to strict operational need',
            'annex_a' => ['A.8.2'],
        ],
        30 => [
            'theme'   => 'VII',
            'fr'      => 'Prendre des mesures de sécurisation physique des terminaux nomades',
            'en'      => 'Physically secure mobile devices',
            'annex_a' => ['A.7.9', 'A.8.1'],
        ],
        31 => [
            'theme'   => 'VII',
            'fr'      => 'Chiffrer les données sensibles, en particulier sur le matériel potentiellement perdable',
            'en'      => 'Encrypt sensitive data, especially on equipment that may be lost',
            'annex_a' => ['A.8.1', 'A.8.24'],
        ],
        32 => [
            'theme'   => 'VII',
            'fr'      => 'Sécuriser la connexion réseau des postes utilisés en situation de nomadisme',
            'en'      => 'Secure the network connection of devices used while working remotely',
            'annex_a' => ['A.6.7', 'A.8.20'],
        ],
        33 => [
            'theme'   => 'VII',
            'fr'      => 'Adopter des politiques de sécurité dédiées aux terminaux mobiles',
            'en'      => 'Adopt security policies dedicated to mobile devices',
            'annex_a' => ['A.6.7', 'A.8.1'],
        ],
        34 => [
            'theme'   => 'VIII',
            'fr'      => 'Définir une politique de mise à jour des composants du système d\'information',
            'en'      => 'Define an update policy for information system components',
            'annex_a' => ['A.8.8', 'A.8.19'],
        ],
        35 => [
            'theme'   => 'VIII',
            'fr'      => 'Anticiper la fin de la maintenance des logiciels et systèmes et limiter les adhérences '
                . 'logicielles',
            'en'      => 'Anticipate end of support for software and systems and limit software dependencies',
            'annex_a' => ['A.8.8'],
        ],
        36 => [
            'theme'   => 'IX',
            'fr'      => 'Activer et configurer les journaux des composants les plus importants',
            'en'      => 'Enable and configure logs on the most important components',
            'annex_a' => ['A.8.15', 'A.8.16'],
        ],
        37 => [
            'theme'   => 'IX',
            'fr'      => 'Définir et appliquer une politique de sauvegarde des composants critiques',
            'en'      => 'Define and apply a backup policy for critical components',
            'annex_a' => ['A.8.13'],
        ],
        38 => [
            'theme'   => 'IX',
            'fr'      => 'Procéder à des contrôles et audits de sécurité réguliers puis appliquer les actions '
                . 'correctives associées',
            'en'      => 'Carry out regular security checks and audits, then apply the corrective actions',
            'annex_a' => ['A.5.35', 'A.5.36', 'A.8.8'],
        ],
        39 => [
            'theme'   => 'IX',
            'fr'      => 'Désigner un référent en sécurité des systèmes d\'information et le faire connaître '
                . 'auprès du personnel',
            'en'      => 'Appoint an information systems security officer and make them known to staff',
            'annex_a' => ['A.5.2'],
        ],
        40 => [
            'theme'   => 'IX',
            'fr'      => 'Définir une procédure de gestion des incidents de sécurité',
            'en'      => 'Define a security incident management procedure',
            'annex_a' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.6.8'],
        ],
        41 => [
            'theme'   => 'X',
            'fr'      => 'Mener une analyse de risques formelle',
            'en'      => 'Carry out a formal risk analysis',
            'annex_a' => [],
        ],
        42 => [
            'theme'   => 'X',
            'fr'      => 'Privilégier l\'usage de produits et de services qualifiés par l\'ANSSI',
            'en'      => 'Prefer products and services qualified by ANSSI',
            'annex_a' => ['A.5.19', 'A.5.21'],
        ],
    ];

    /**
     * Correspondance inverse : contrôle Annexe A => numéros des mesures ANSSI qui y renvoient.
     *
     * @return list<int>
     */
    public static function measuresForControl(string $annexACode): array
    {
        $numbers = [];
        foreach (self::MEASURES as $number => $measure) {
            if (in_array($annexACode, $measure['annex_a'], true)) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }
}

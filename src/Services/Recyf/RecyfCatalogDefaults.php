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

namespace GlpiPlugin\Grcmanager\Services\Recyf;

use GlpiPlugin\Grcmanager\Services\Control\CatalogLanguage;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;

/**
 * Issue #599 : Référentiel Cyber France (ReCyF) de l'ANSSI, moyens acceptables de conformité aux
 * objectifs de sécurité de la transposition française de NIS2. Relevé sur la version de travail
 * 2.5 du 17 mars 2026 publiée par l'ANSSI (SOURCE_DOCUMENT, lien sur SOURCE_PAGE) : 20 objectifs,
 * 152 mesures. Les objectifs 1 à 15 s'appliquent aux entités importantes et essentielles, les
 * objectifs 16 à 20 aux seules entités essentielles ; une mesure suffixée « -EE » ne vaut que pour
 * les entités essentielles (`essential` = true), une mesure « -EI/EE » pour les deux.
 *
 * Seuls les numéros (objectif, sous-partie, mesure) et leur suffixe sont repris de la source. Les
 * intitulés et les résumés (`fr`, `en`) sont écrits par ce projet avec ses propres mots : ils ne
 * remplacent pas le texte de l'ANSSI, seul authentique. Version de travail « susceptible
 * d'ajustements » selon l'ANSSI : à relire à chaque nouvelle version (VERSION).
 *
 * `nis2` : renvoi indicatif, établi par ce projet, de chaque objectif vers les exigences de la
 * directive (UE) 2022/2555 (clés de Nis2CatalogDefaults::REQUIREMENTS).
 *
 * Logique pure, sans GLPI.
 */
final class RecyfCatalogDefaults
{
    public const VERSION = '2.5';
    public const VERSION_DATE = '2026-03-17';
    public const SOURCE_PAGE = 'https://messervices.cyber.gouv.fr/nis2';
    public const SOURCE_DOCUMENT
        = 'https://messervices.cyber.gouv.fr/documents-ressources/20260317_NIS_V2_ReCyF_v2.5.pdf';

    public const CATEGORY_IMPORTANT = 'important';
    public const CATEGORY_ESSENTIAL = 'essential';
    public const CATEGORIES_ENTITY = [self::CATEGORY_IMPORTANT, self::CATEGORY_ESSENTIAL];

    /**
     * Objectifs de sécurité, par numéro. `essential` : objectif réservé aux entités essentielles.
     *
     * @var array<int, array{fr: string, en: string, essential: bool, nis2: list<string>}>
     */
    public const OBJECTIVES = [
        1 => [
            'fr' => 'Recensement des systèmes d\'information',
            'en' => 'Inventory of information systems',
            'essential' => false,
            'nis2' => ['21.2.a', '21.2.i'],
        ],
        2 => [
            'fr' => 'Gouvernance de la sécurité numérique',
            'en' => 'Digital security governance',
            'essential' => false,
            'nis2' => ['20.1', '21.2.a', '21.2.f'],
        ],
        3 => [
            'fr' => 'Maîtrise de l\'écosystème',
            'en' => 'Control of the ecosystem',
            'essential' => false,
            'nis2' => ['21.2.d'],
        ],
        4 => [
            'fr' => 'Sécurité dans la gestion des ressources humaines',
            'en' => 'Security in human resources management',
            'essential' => false,
            'nis2' => ['20.2', '21.2.g', '21.2.i'],
        ],
        5 => [
            'fr' => 'Maîtrise des systèmes d\'information',
            'en' => 'Control of information systems',
            'essential' => false,
            'nis2' => ['21.2.e', '21.2.i'],
        ],
        6 => [
            'fr' => 'Accès physiques aux locaux',
            'en' => 'Physical access to premises',
            'essential' => false,
            'nis2' => ['21.2.i'],
        ],
        7 => [
            'fr' => 'Architecture des systèmes d\'information',
            'en' => 'Information system architecture',
            'essential' => false,
            'nis2' => ['21.2.e'],
        ],
        8 => [
            'fr' => 'Accès distants',
            'en' => 'Remote access',
            'essential' => false,
            'nis2' => ['21.2.h', '21.2.j'],
        ],
        9 => [
            'fr' => 'Protection contre les codes malveillants',
            'en' => 'Protection against malicious code',
            'essential' => false,
            'nis2' => ['21.2.e', '21.2.g'],
        ],
        10 => [
            'fr' => 'Identités et accès des utilisateurs',
            'en' => 'User identities and access',
            'essential' => false,
            'nis2' => ['21.2.i', '21.2.j'],
        ],
        11 => [
            'fr' => 'Maîtrise de l\'administration',
            'en' => 'Control of administration',
            'essential' => false,
            'nis2' => ['21.2.i'],
        ],
        12 => [
            'fr' => 'Détection et traitement des incidents',
            'en' => 'Incident detection and handling',
            'essential' => false,
            'nis2' => ['21.2.b'],
        ],
        13 => [
            'fr' => 'Continuité et reprise d\'activité',
            'en' => 'Business continuity and recovery',
            'essential' => false,
            'nis2' => ['21.2.c'],
        ],
        14 => [
            'fr' => 'Gestion des crises d\'origine cyber',
            'en' => 'Cyber crisis management',
            'essential' => false,
            'nis2' => ['21.2.b', '21.2.c'],
        ],
        15 => [
            'fr' => 'Exercices et entraînements',
            'en' => 'Exercises and training',
            'essential' => false,
            'nis2' => ['21.2.c', '21.2.f'],
        ],
        16 => [
            'fr' => 'Approche par les risques',
            'en' => 'Risk-based approach',
            'essential' => true,
            'nis2' => ['20.1', '21.2.a'],
        ],
        17 => [
            'fr' => 'Audits de sécurité',
            'en' => 'Security audits',
            'essential' => true,
            'nis2' => ['21.2.f'],
        ],
        18 => [
            'fr' => 'Configuration sécurisée des ressources',
            'en' => 'Secure configuration of resources',
            'essential' => true,
            'nis2' => ['21.2.e'],
        ],
        19 => [
            'fr' => 'Administration depuis des ressources dédiées',
            'en' => 'Administration from dedicated resources',
            'essential' => true,
            'nis2' => ['21.2.i', '21.2.j'],
        ],
        20 => [
            'fr' => 'Supervision de la sécurité',
            'en' => 'Security monitoring',
            'essential' => true,
            'nis2' => ['21.2.b'],
        ],
    ];

    /**
     * Sous-parties des objectifs qui en ont (« 2.A »).
     *
     * @var array<string, array{fr: string, en: string}>
     */
    public const SECTIONS = [
        '2.A' => [
            'fr' => 'Rôles et responsabilités',
            'en' => 'Roles and responsibilities',
        ],
        '2.B' => [
            'fr' => 'Politique de sécurité des SI',
            'en' => 'Information systems security policy',
        ],
        '2.C' => [
            'fr' => 'Gestion de la conformité',
            'en' => 'Compliance management',
        ],
        '3.A' => [
            'fr' => 'Cartographie de l\'écosystème',
            'en' => 'Ecosystem map',
        ],
        '3.B' => [
            'fr' => 'Sécurité dans les contrats informatiques',
            'en' => 'Security in IT contracts',
        ],
        '5.A' => [
            'fr' => 'Cartographie des SI',
            'en' => 'Map of the systems',
        ],
        '5.B' => [
            'fr' => 'Maintien en condition opérationnelle et de sécurité',
            'en' => 'Operational and security maintenance',
        ],
        '7.A' => [
            'fr' => 'Cloisonnement',
            'en' => 'Segmentation',
        ],
        '7.B' => [
            'fr' => 'Filtrage des communications',
            'en' => 'Filtering of communications',
        ],
        '10.A' => [
            'fr' => 'Identification',
            'en' => 'Identification',
        ],
        '10.B' => [
            'fr' => 'Authentification',
            'en' => 'Authentication',
        ],
        '10.C' => [
            'fr' => 'Droits d\'accès',
            'en' => 'Access rights',
        ],
        '11.A' => [
            'fr' => 'Comptes d\'administration',
            'en' => 'Administration accounts',
        ],
        '11.B' => [
            'fr' => 'Sécurité des annuaires',
            'en' => 'Directory security',
        ],
    ];

    /**
     * Mesures, dans l'ordre du référentiel, par numéro sans suffixe (« 2.A.2 »).
     *
     * @var array<string, array{fr: string, en: string, essential?: bool}>
     */
    public const MEASURES = [
        '1.1' => [
            'fr' => 'Lister toutes les activités et tous les services de l\'entité, chacun avec un responsable et '
                . 'les SI qui le supportent.',
            'en' => 'List every activity and service of the entity, each with an owner and the systems supporting '
                . 'it.',
        ],
        '1.2' => [
            'fr' => 'Signaler dans cette liste les SI laissés hors du champ faute d\'exposition aux risques, avec '
                . 'la justification.',
            'en' => 'Flag in this list the systems left out because they are not exposed to the risks, with the '
                . 'reason.',
        ],
        '1.3' => [
            'fr' => 'Valider et revoir la liste chaque année et à chaque changement notable (nouvelle activité, '
                . 'nouveau SI).',
            'en' => 'Approve and review the list every year and whenever activities or systems change.',
        ],
        '2.A.1' => [
            'fr' => 'Le dirigeant exécutif porte la responsabilité de la sécurité numérique et du suivi de la '
                . 'conformité.',
            'en' => 'The chief executive is accountable for digital security and for following up compliance.',
        ],
        '2.A.2' => [
            'essential' => true,
            'fr' => 'Désigner au moins une personne qui conseille le dirigeant et sert de contact privilégié de '
                . 'l\'ANSSI.',
            'en' => 'Appoint at least one adviser to the chief executive, who is the main contact of ANSSI.',
        ],
        '2.A.3' => [
            'fr' => 'Mettre en place une organisation de la sécurité adaptée (responsable sécurité, matrice RACI, '
                . 'comités).',
            'en' => 'Set up a suitable security organisation (security officer, RACI matrix, committees).',
        ],
        '2.B.1' => [
            'fr' => 'Rédiger et appliquer une politique de sécurité des systèmes d\'information (PSSI).',
            'en' => 'Write and apply an information systems security policy.',
        ],
        '2.B.2' => [
            'fr' => 'Contenu minimal de la PSSI : gouvernance et rôles, orientations, engagements du dirigeant, '
                . 'exigences du secteur.',
            'en' => 'Minimum content of the policy: governance and roles, direction, commitments of the chief '
                . 'executive, sector requirements.',
        ],
        '2.B.3' => [
            'fr' => 'Faire approuver la PSSI par le dirigeant exécutif.',
            'en' => 'Have the policy approved by the chief executive.',
        ],
        '2.B.4' => [
            'fr' => 'Revoir la PSSI au moins chaque année et après tout changement majeur de menace ou de '
                . 'contexte.',
            'en' => 'Review the policy at least yearly and after any major change in threat or context.',
        ],
        '2.B.5' => [
            'fr' => 'Décliner la PSSI en politiques thématiques, au moins : chiffrement, contrôle d\'accès '
                . 'physique et logique, revue des mesures, gestion des comptes.',
            'en' => 'Break the policy down into topic policies, at least: encryption, physical and logical access '
                . 'control, review of measures, account management.',
        ],
        '2.C.1' => [
            'fr' => 'Analyser et tenir à jour la conformité de chaque SI aux mesures du référentiel, en tenant '
                . 'compte de la PSSI.',
            'en' => 'Assess and keep up to date the compliance of each system with the framework measures, in '
                . 'line with the policy.',
        ],
        '2.C.2' => [
            'fr' => 'Établir et suivre un plan d\'action pour corriger les écarts, avec une échéance et un '
                . 'responsable par action.',
            'en' => 'Draw up and follow an action plan to close the gaps, with a deadline and an owner for each '
                . 'action.',
        ],
        '2.C.3' => [
            'fr' => 'Justifier dans l\'analyse de conformité toute mesure alternative retenue à la place d\'une '
                . 'mesure du référentiel.',
            'en' => 'Justify in the compliance analysis any alternative measure used instead of a framework '
                . 'measure.',
        ],
        '3.A.1' => [
            'fr' => 'Tenir à jour une cartographie de l\'écosystème : prestataires et fournisseurs informatiques, '
                . 'interconnexions avec les SI.',
            'en' => 'Keep an up-to-date map of the ecosystem: IT providers and suppliers, interconnections with '
                . 'the systems.',
        ],
        '3.A.2' => [
            'fr' => 'Disposer d\'au moins un point de contact à jour pour chaque entrée de cette cartographie.',
            'en' => 'Keep at least one up-to-date contact for each entry of this map.',
        ],
        '3.B.1' => [
            'fr' => 'S\'assurer, par contrat, que les prestations informatiques respectent les obligations de '
                . 'l\'entité (gestion des risques, notification des incidents).',
            'en' => 'Make sure, through contracts, that IT services meet the obligations of the entity (risk '
                . 'management, incident notification).',
        ],
        '3.B.2' => [
            'fr' => 'Vérifier périodiquement la conformité des prestations, par exemple au moyen d\'audits.',
            'en' => 'Periodically check that the services remain compliant, for example through audits.',
        ],
        '4.1' => [
            'fr' => 'Adopter une charte d\'usage des SI opposable à chaque utilisateur.',
            'en' => 'Adopt an acceptable use charter binding on every user.',
        ],
        '4.2' => [
            'fr' => 'Mener un programme de sensibilisation de tous les utilisateurs, tout au long de leur '
                . 'présence.',
            'en' => 'Run an awareness programme for all users throughout their time in the entity.',
        ],
        '4.3' => [
            'essential' => true,
            'fr' => 'Prévoir des clauses de sécurité, dont la confidentialité, dans les contrats de travail.',
            'en' => 'Include security clauses, such as confidentiality, in employment contracts.',
        ],
        '4.4' => [
            'fr' => 'Gérer arrivées, changements de fonction et départs : règles communiquées, accès attribués, '
                . 'ajustés puis retirés, matériel restitué.',
            'en' => 'Handle joiners, movers and leavers: rules shared, access granted, adjusted then removed, '
                . 'equipment returned.',
        ],
        '4.5' => [
            'fr' => 'Former à la sécurité numérique les personnes qui ont des responsabilités dans le numérique, '
                . 'selon leur rôle.',
            'en' => 'Train people with digital responsibilities in digital security, according to their role.',
        ],
        '5.A.1' => [
            'fr' => 'Tenir à jour une cartographie des SI assez détaillée pour repérer les éléments vulnérables '
                . 'ou touchés par un incident.',
            'en' => 'Keep a map of the systems detailed enough to find vulnerable or affected components.',
        ],
        '5.B.1' => [
            'essential' => true,
            'fr' => 'Rédiger et appliquer une procédure de maintien en condition opérationnelle et de sécurité.',
            'en' => 'Write and apply a procedure for operational and security maintenance.',
        ],
        '5.B.2' => [
            'fr' => 'Tenir à jour les bases des outils de protection contre les logiciels malveillants '
                . '(antivirus, EDR).',
            'en' => 'Keep the databases of anti-malware tools up to date (antivirus, EDR).',
        ],
        '5.B.3' => [
            'fr' => 'Assurer une veille sur les vulnérabilités, les correctifs et les mesures d\'atténuation '
                . '(éditeurs, CERT-FR, CSIRT).',
            'en' => 'Monitor vulnerabilities, patches and mitigations (vendors, CERT-FR, CSIRTs).',
        ],
        '5.B.4' => [
            'fr' => 'Préparer sans attendre puis appliquer rapidement les correctifs sur les ressources exposées '
                . 'et les postes de travail.',
            'en' => 'Prepare patches at once, then apply them promptly on exposed resources and workstations.',
        ],
        '5.B.5' => [
            'essential' => true,
            'fr' => 'Planifier et installer les correctifs sur toutes les ressources, y compris celles qui ne '
                . 'sont pas exposées.',
            'en' => 'Plan and install patches on all resources, including those not exposed.',
        ],
        '5.B.6' => [
            'fr' => 'Réduire le risque (isolement, accès renforcé) quand un correctif ne peut pas être installé.',
            'en' => 'Reduce the risk (isolation, stronger access control) when a patch cannot be installed.',
        ],
        '5.B.7' => [
            'fr' => 'Utiliser des logiciels, y compris embarqués, dans des versions à jour et encore supportées '
                . 'par leur fournisseur.',
            'en' => 'Run software, firmware included, in versions that are up to date and still supported by the '
                . 'supplier.',
        ],
        '5.B.8' => [
            'fr' => 'Télécharger les nouvelles versions uniquement depuis les sources officielles des éditeurs.',
            'en' => 'Download new versions only from the official sources of the vendors.',
        ],
        '5.B.9' => [
            'fr' => 'Réduire le risque lorsqu\'une version qui n\'est plus supportée doit être conservée.',
            'en' => 'Reduce the risk when an unsupported version has to be kept.',
        ],
        '6.1' => [
            'fr' => 'Limiter l\'accès des personnes non autorisées aux locaux, salles serveurs et locaux '
                . 'techniques (badges, registre des visiteurs).',
            'en' => 'Keep unauthorised people out of the premises, server rooms and technical rooms (badges, '
                . 'visitor log).',
        ],
        '6.2' => [
            'essential' => true,
            'fr' => 'Protéger physiquement ces locaux pour prévenir, détecter et traiter les intrusions (alarme, '
                . 'vidéo, gardiennage).',
            'en' => 'Protect these rooms physically to prevent, detect and respond to intrusions (alarm, video, '
                . 'guards).',
        ],
        '6.3' => [
            'essential' => true,
            'fr' => 'Attribuer les droits d\'accès physique au strict besoin des missions de chacun.',
            'en' => 'Grant physical access rights only as strictly needed for each person\'s duties.',
        ],
        '6.4' => [
            'fr' => 'Accompagner ou autoriser formellement les personnes externes dans les salles serveurs et '
                . 'locaux techniques.',
            'en' => 'Escort or formally authorise external people in server and technical rooms.',
        ],
        '7.A.1' => [
            'fr' => 'Cloisonner l\'ensemble des SI vis-à-vis des SI tiers et des SI laissés hors du champ.',
            'en' => 'Segregate the systems as a whole from third-party and out-of-scope systems.',
        ],
        '7.A.2' => [
            'essential' => true,
            'fr' => 'Cloisonner chaque SI vis-à-vis des autres SI.',
            'en' => 'Segregate each system from the other systems.',
        ],
        '7.A.3' => [
            'essential' => true,
            'fr' => 'Étudier pour chaque SI un découpage en sous-systèmes homogènes, ou justifier qu\'il n\'y en a '
                . 'pas.',
            'en' => 'Consider splitting each system into homogeneous sub-systems, or justify why not.',
        ],
        '7.A.4' => [
            'essential' => true,
            'fr' => 'Cloisonner les sous-systèmes entre eux.',
            'en' => 'Segregate the sub-systems from each other.',
        ],
        '7.A.5' => [
            'essential' => true,
            'fr' => 'Mettre en place une passerelle sortante qui authentifie, filtre et trace les accès vers '
                . 'l\'extérieur (proxy).',
            'en' => 'Provide an outbound gateway that authenticates, filters and logs access to the outside '
                . '(proxy).',
        ],
        '7.A.6' => [
            'essential' => true,
            'fr' => 'Mettre en place une passerelle entrante qui filtre et trace les accès depuis l\'extérieur '
                . '(reverse proxy, relais).',
            'en' => 'Provide an inbound gateway that filters and logs access from the outside (reverse proxy, '
                . 'relay).',
        ],
        '7.A.7' => [
            'fr' => 'N\'ouvrir que les interconnexions nécessaires entre les SI et les SI tiers ou hors du champ.',
            'en' => 'Open only the interconnections needed between the systems and third-party or out-of-scope '
                . 'systems.',
        ],
        '7.A.8' => [
            'essential' => true,
            'fr' => 'Appliquer la même règle à chaque SI et entre ses sous-systèmes.',
            'en' => 'Apply the same rule to each system and between its sub-systems.',
        ],
        '7.B.1' => [
            'fr' => 'Documenter les flux nécessaires (métier et maintenance) avec les SI tiers ou hors du champ.',
            'en' => 'Document the needed flows (business and maintenance) with third-party or out-of-scope '
                . 'systems.',
        ],
        '7.B.2' => [
            'essential' => true,
            'fr' => 'Documenter les flux de maintenance entre sous-systèmes.',
            'en' => 'Document the maintenance flows between sub-systems.',
        ],
        '7.B.3' => [
            'fr' => 'Filtrer aux interconnexions pour n\'autoriser que les flux documentés, tout le reste étant '
                . 'bloqué par défaut.',
            'en' => 'Filter at interconnections to allow only documented flows, blocking everything else by '
                . 'default.',
        ],
        '7.B.4' => [
            'fr' => 'Filtrer au moins les échanges avec les SI tiers par un ou plusieurs pare-feux dédiés.',
            'en' => 'Filter at least the traffic with third-party systems through one or more dedicated firewalls.',
        ],
        '7.B.5' => [
            'fr' => 'Revoir chaque année la mise en œuvre des règles de filtrage.',
            'en' => 'Review the implementation of the filtering rules every year.',
        ],
        '8.1' => [
            'fr' => 'Chiffrer les accès aux SI qui passent par un SI tiers (VPN, TLS, SSH).',
            'en' => 'Encrypt access to the systems through third-party systems (VPN, TLS, SSH).',
        ],
        '8.2' => [
            'fr' => 'Authentifier les personnels et prestataires qui accèdent à distance, selon les mesures '
                . 'd\'authentification.',
            'en' => 'Authenticate staff and providers connecting remotely, following the authentication measures.',
        ],
        '8.3' => [
            'essential' => true,
            'fr' => 'Exiger pour ces accès une authentification multifacteur qui comprend un facteur de '
                . 'connaissance.',
            'en' => 'Require multi-factor authentication including a knowledge factor for this access.',
        ],
        '8.4' => [
            'essential' => true,
            'fr' => 'Réduire le risque quand l\'authentification multifacteur est impossible.',
            'en' => 'Reduce the risk when multi-factor authentication is not possible.',
        ],
        '8.5' => [
            'essential' => true,
            'fr' => 'Chiffrer en permanence les disques des postes et appareils mobiles utilisés à distance.',
            'en' => 'Keep the disks of laptops and mobile devices used remotely encrypted at all times.',
        ],
        '9.1' => [
            'fr' => 'Définir les équipements autorisés à se connecter aux SI (les équipements personnels restent '
                . 'possibles).',
            'en' => 'Define which devices may connect to the systems (personal devices remain possible).',
        ],
        '9.2' => [
            'essential' => true,
            'fr' => 'N\'autoriser que les équipements gérés par l\'entité ou son prestataire (pas d\'équipement '
                . 'personnel).',
            'en' => 'Allow only devices managed by the entity or its provider (no personal devices).',
        ],
        '9.3' => [
            'fr' => 'Empêcher la connexion des équipements qui ne sont pas autorisés au titre de 9.1.',
            'en' => 'Prevent the connection of devices not allowed under 9.1.',
        ],
        '9.4' => [
            'essential' => true,
            'fr' => 'Empêcher la connexion des équipements qui ne sont pas gérés au titre de 9.2.',
            'en' => 'Prevent the connection of unmanaged devices under 9.2.',
        ],
        '9.5' => [
            'fr' => 'Ne connecter que les supports amovibles réinscriptibles nécessaires.',
            'en' => 'Connect only the rewritable removable media that are needed.',
        ],
        '9.6' => [
            'fr' => 'Équiper d\'un antivirus ou d\'un EDR les postes, serveurs et mobiles qui traitent des données '
                . 'externes.',
            'en' => 'Equip workstations, servers and mobiles handling external data with antivirus or EDR.',
        ],
        '9.7' => [
            'fr' => 'Analyser les données externes dès leur réception (passerelle de messagerie, station blanche).',
            'en' => 'Scan external data on arrival (mail gateway, USB cleaning station).',
        ],
        '10.A.1' => [
            'fr' => 'Attribuer des comptes individuels aux utilisateurs et aux processus automatiques.',
            'en' => 'Give individual accounts to users and automated processes.',
        ],
        '10.A.2' => [
            'fr' => 'Réserver l\'usage d\'un compte individuel à son titulaire.',
            'en' => 'Restrict an individual account to the person or process it was issued to.',
        ],
        '10.A.3' => [
            'fr' => 'Encadrer et tracer l\'usage des comptes partagés quand un compte individuel est impossible.',
            'en' => 'Control and log shared accounts when individual accounts are not possible.',
        ],
        '10.A.4' => [
            'fr' => 'Aucun compte n\'est exigé pour consulter l\'information diffusée au public (site vitrine).',
            'en' => 'No account is required to read information published to the public (showcase website).',
        ],
        '10.A.5' => [
            'fr' => 'Désactiver les comptes inutiles dans le délai fixé par la politique de gestion des comptes.',
            'en' => 'Disable unused accounts within the time set by the account policy.',
        ],
        '10.A.6' => [
            'fr' => 'Revoir les comptes au moins une fois par an et corriger les anomalies.',
            'en' => 'Review accounts at least once a year and fix anomalies.',
        ],
        '10.B.1' => [
            'fr' => 'Protéger les accès par une authentification qui repose sur au moins un secret.',
            'en' => 'Protect access with authentication based on at least one secret.',
        ],
        '10.B.2' => [
            'fr' => 'Changer les secrets par défaut avant la mise en service d\'une ressource.',
            'en' => 'Change default secrets before a resource goes live.',
        ],
        '10.B.3' => [
            'fr' => 'Renouveler le secret d\'un compte partagé chaque fois qu\'un de ses utilisateurs le quitte.',
            'en' => 'Renew the secret of a shared account whenever one of its users leaves it.',
        ],
        '10.B.4' => [
            'fr' => 'Ne faire connaître un secret qu\'aux utilisateurs autorisés du compte (coffre-fort de mots de '
                . 'passe).',
            'en' => 'Share a secret only with the authorised users of the account (password vault).',
        ],
        '10.B.5' => [
            'fr' => 'Respecter les recommandations de l\'ANSSI sur la robustesse et le renouvellement des facteurs '
                . 'd\'authentification.',
            'en' => 'Follow ANSSI recommendations on strength and renewal of authentication factors.',
        ],
        '10.B.6' => [
            'fr' => 'Si un secret ne peut pas être changé, restreindre l\'accès à la ressource et réduire le '
                . 'risque.',
            'en' => 'If a secret cannot be changed, restrict access to the resource and reduce the risk.',
        ],
        '10.B.7' => [
            'essential' => true,
            'fr' => 'Dans ce cas, tracer aussi les accès à la ressource.',
            'en' => 'In that case, also log access to the resource.',
        ],
        '10.C.1' => [
            'fr' => 'N\'accorder des droits qu\'à des utilisateurs et processus authentifiés.',
            'en' => 'Grant rights only to authenticated users and processes.',
        ],
        '10.C.2' => [
            'fr' => 'Limiter les droits de chacun aux ressources dont il a besoin.',
            'en' => 'Limit everyone\'s rights to the resources they need.',
        ],
        '10.C.3' => [
            'fr' => 'Pour chaque ressource, n\'ouvrir l\'accès qu\'à ceux qui en ont besoin pour leurs missions.',
            'en' => 'For each resource, give access only to those who need it for their duties.',
        ],
        '10.C.4' => [
            'fr' => 'Revoir les droits d\'accès au moins une fois par an et corriger les anomalies.',
            'en' => 'Review access rights at least once a year and fix anomalies.',
        ],
        '11.A.1' => [
            'fr' => 'Administrer uniquement avec des comptes d\'administration, qui ne servent à rien d\'autre.',
            'en' => 'Administer only with administration accounts, used for nothing else.',
        ],
        '11.A.2' => [
            'fr' => 'Réserver les comptes d\'administration aux administrateurs et aux personnes autorisées.',
            'en' => 'Restrict administration accounts to administrators and authorised people.',
        ],
        '11.A.3' => [
            'fr' => 'Appliquer aux comptes d\'administration les mesures de gestion des identités et des accès.',
            'en' => 'Apply the identity and access measures to administration accounts.',
        ],
        '11.A.4' => [
            'essential' => true,
            'fr' => 'N\'utiliser un compte d\'administration que sur les ressources qu\'il administre ou depuis une '
                . 'ressource d\'administration.',
            'en' => 'Use an administration account only on the resources it manages or from an administration '
                . 'resource.',
        ],
        '11.A.5' => [
            'fr' => 'Contrôler les actions et réduire le risque quand un compte dédié ne peut pas être utilisé.',
            'en' => 'Control the actions and reduce the risk when a dedicated account cannot be used.',
        ],
        '11.A.6' => [
            'essential' => true,
            'fr' => 'Tenir à jour la liste des comptes d\'administration.',
            'en' => 'Keep the list of administration accounts up to date.',
        ],
        '11.A.7' => [
            'essential' => true,
            'fr' => 'À chaque modification d\'un compte d\'administration, vérifier que ses droits restent limités '
                . 'à son périmètre.',
            'en' => 'On each change to an administration account, check that its rights stay limited to its scope.',
        ],
        '11.B.1' => [
            'fr' => 'Corriger rapidement les vulnérabilités des annuaires (utilisateurs, ressources).',
            'en' => 'Patch directories (users, resources) promptly.',
        ],
        '11.B.2' => [
            'essential' => true,
            'fr' => 'Identifier, pour chaque annuaire, les ressources de son cœur de confiance.',
            'en' => 'Identify, for each directory, the resources of its trust core (tier 0).',
        ],
        '11.B.3' => [
            'essential' => true,
            'fr' => 'Administrer le cœur de confiance avec des comptes dédiés.',
            'en' => 'Administer the trust core with dedicated accounts.',
        ],
        '11.B.4' => [
            'essential' => true,
            'fr' => 'Administrer le cœur de confiance depuis des ressources dédiées.',
            'en' => 'Administer the trust core from dedicated resources.',
        ],
        '11.B.5' => [
            'essential' => true,
            'fr' => 'Bloquer par filtrage les connexions externes vers les ressources d\'administration du cœur de '
                . 'confiance.',
            'en' => 'Block, by filtering, outside connections to the trust core administration resources.',
        ],
        '11.B.6' => [
            'essential' => true,
            'fr' => 'Revoir chaque année la configuration des annuaires, de préférence avec un outil.',
            'en' => 'Review directory configuration every year, preferably with a tool.',
        ],
        '11.B.7' => [
            'essential' => true,
            'fr' => 'Appliquer les recommandations de l\'ANSSI relatives au cœur de confiance.',
            'en' => 'Apply ANSSI recommendations about the trust core.',
        ],
        '12.1' => [
            'essential' => true,
            'fr' => 'Disposer d\'une procédure de traitement des incidents de sécurité tenue à jour.',
            'en' => 'Maintain and apply a security incident handling procedure.',
        ],
        '12.2' => [
            'essential' => true,
            'fr' => 'Disposer d\'outils pour recueillir les signalements (salariés, clients, fournisseurs).',
            'en' => 'Provide tools to collect reports (staff, customers, suppliers).',
        ],
        '12.3' => [
            'fr' => 'Analyser et qualifier les événements signalés pour repérer les incidents.',
            'en' => 'Analyse and qualify reported events to spot incidents.',
        ],
        '12.4' => [
            'essential' => true,
            'fr' => 'Prévoir les moyens de réagir et de limiter l\'impact d\'un incident sur les services.',
            'en' => 'Have the means to respond and limit the impact of an incident on services.',
        ],
        '12.5' => [
            'essential' => true,
            'fr' => 'Analyser les causes après chaque incident, en tirer des mesures et en garder la preuve.',
            'en' => 'Analyse root causes after each incident, derive measures and keep evidence.',
        ],
        '12.6' => [
            'fr' => 'Conserver les éléments techniques utiles comme preuves (rapports, alertes), pendant une '
                . 'durée adaptée.',
            'en' => 'Keep useful technical records as evidence (reports, alerts), for a suitable period.',
        ],
        '12.7' => [
            'essential' => true,
            'fr' => 'Protéger ces éléments contre un incident qui les rendrait inutilisables (copie hors ligne).',
            'en' => 'Protect these records against an incident that would make them unusable (offline copy).',
        ],
        '13.1' => [
            'fr' => 'Définir et appliquer des procédures de sauvegarde et de restauration.',
            'en' => 'Define and apply backup and restore procedures.',
        ],
        '13.2' => [
            'fr' => 'Tester la sauvegarde et la restauration au moins une fois par an.',
            'en' => 'Test backup and restore at least once a year.',
        ],
        '13.3' => [
            'fr' => 'Protéger les sauvegardes contre un incident (stockage hors ligne).',
            'en' => 'Protect backups against an incident (offline storage).',
        ],
        '13.4' => [
            'essential' => true,
            'fr' => 'Fixer pour chaque activité la durée maximale d\'interruption admissible et la perte de '
                . 'données acceptable.',
            'en' => 'Set for each activity the maximum tolerable downtime and acceptable data loss.',
        ],
        '13.5' => [
            'fr' => 'Dimensionner les sauvegardes selon les besoins de disponibilité.',
            'en' => 'Size backups according to availability needs.',
        ],
        '13.6' => [
            'essential' => true,
            'fr' => 'Établir un plan de continuité et un plan de reprise adaptés aux crises d\'origine cyber.',
            'en' => 'Set up continuity and recovery plans suited to cyber crises.',
        ],
        '13.7' => [
            'essential' => true,
            'fr' => 'Bâtir ces plans sur la cartographie de l\'écosystème et les procédures d\'incident et de '
                . 'crise.',
            'en' => 'Base these plans on the ecosystem map and the incident and crisis procedures.',
        ],
        '14.1' => [
            'fr' => 'Disposer d\'une procédure de gestion de crise pour les incidents graves.',
            'en' => 'Maintain a crisis management procedure for serious incidents.',
        ],
        '14.2' => [
            'fr' => 'Tenir à jour une liste imprimée des personnes mobilisables et de leurs coordonnées.',
            'en' => 'Keep an up-to-date printed list of people to call on and their contact details.',
        ],
        '14.3' => [
            'fr' => 'Garder cette liste accessible quel que soit le scénario (papier ou numérique).',
            'en' => 'Keep that list reachable whatever happens (paper or digital).',
        ],
        '14.4' => [
            'fr' => 'Tenir à jour l\'annuaire des parties prenantes externes utiles en crise.',
            'en' => 'Keep a directory of external stakeholders useful in a crisis.',
        ],
        '14.5' => [
            'essential' => true,
            'fr' => 'Tirer un retour d\'expérience de chaque entraînement, exercice ou crise.',
            'en' => 'Hold a lessons-learned review after each drill, exercise or crisis.',
        ],
        '14.6' => [
            'essential' => true,
            'fr' => 'Définir les critères de déclenchement et de levée du dispositif de crise.',
            'en' => 'Define the criteria to trigger and stand down the crisis set-up.',
        ],
        '14.7' => [
            'essential' => true,
            'fr' => 'Adapter les procédures et les moyens de crise à la menace cyber, selon les recommandations '
                . 'de l\'ANSSI.',
            'en' => 'Adapt crisis procedures and means to cyber threats, following ANSSI recommendations.',
        ],
        '14.8' => [
            'essential' => true,
            'fr' => 'Prévoir l\'isolement, la protection et la reconstruction des SI touchés, prestations '
                . 'externalisées comprises.',
            'en' => 'Plan isolation, protection and rebuilding of affected systems, outsourced services included.',
        ],
        '14.9' => [
            'essential' => true,
            'fr' => 'Définir une stratégie de communication de crise cyber (scénarios, organisation, éléments de '
                . 'langage).',
            'en' => 'Define a cyber crisis communication strategy (scenarios, organisation, key messages).',
        ],
        '14.10' => [
            'essential' => true,
            'fr' => 'Disposer de moyens de communication de secours, si possible sécurisés.',
            'en' => 'Have backup communication means, secured if possible.',
        ],
        '15.1' => [
            'fr' => 'Organiser au moins un exercice sur table pour les personnes du dispositif de crise.',
            'en' => 'Run at least one tabletop exercise for the crisis team.',
        ],
        '15.2' => [
            'essential' => true,
            'fr' => 'Définir une stratégie d\'entraînement (acteurs, exercices, objectifs, scénarios, suivi).',
            'en' => 'Define a training strategy (participants, exercises, goals, scenarios, follow-up).',
        ],
        '15.3' => [
            'essential' => true,
            'fr' => 'Faire porter cette stratégie sur les alertes, la continuité et la gestion de crise.',
            'en' => 'Make this strategy cover alerts, continuity and crisis management.',
        ],
        '15.4' => [
            'essential' => true,
            'fr' => 'Décliner la stratégie en un programme d\'exercices sur trois ans.',
            'en' => 'Turn the strategy into a three-year exercise programme.',
        ],
        '16.1' => [
            'essential' => true,
            'fr' => 'Mettre en place une gouvernance des risques numériques portée par la direction, avec les '
                . 'moyens nécessaires.',
            'en' => 'Set up digital risk governance led by management, with the needed resources.',
        ],
        '16.2' => [
            'essential' => true,
            'fr' => 'Couvrir chaque SI par une analyse de risques (par exemple par activité ; EBIOS RM peut '
                . 'servir).',
            'en' => 'Cover each system with a risk analysis (for example per activity; EBIOS RM can be used).',
        ],
        '16.3' => [
            'essential' => true,
            'fr' => 'Valider l\'analyse, accepter les risques résiduels et suivre le plan de traitement.',
            'en' => 'Approve the analysis, accept residual risks and follow the treatment plan.',
        ],
        '16.4' => [
            'essential' => true,
            'fr' => 'Revoir l\'analyse de risques au moins tous les trois ans et après un incident ou un '
                . 'changement majeur.',
            'en' => 'Review the risk analysis at least every three years and after an incident or major change.',
        ],
        '17.1' => [
            'essential' => true,
            'fr' => 'Établir un programme d\'audit de tous les SI, proportionné aux risques et à l\'exposition.',
            'en' => 'Set up an audit programme for all systems, proportionate to risk and exposure.',
        ],
        '17.2' => [
            'essential' => true,
            'fr' => 'Faire réaliser des audits indépendants qui vérifient la conformité et le niveau de sécurité.',
            'en' => 'Have independent audits check compliance and the security level.',
        ],
        '17.3' => [
            'essential' => true,
            'fr' => 'Inclure au moins un type d\'audit : intrusion, configuration, architecture, organisation et '
                . 'physique, code.',
            'en' => 'Include at least one audit type: penetration test, configuration, architecture, '
                . 'organisational and physical, code.',
        ],
        '17.4' => [
            'essential' => true,
            'fr' => 'Obtenir un rapport d\'audit avec synthèse, constats et recommandations.',
            'en' => 'Get an audit report with a summary, findings and recommendations.',
        ],
        '17.5' => [
            'essential' => true,
            'fr' => 'Corriger les constats par un plan d\'action avec échéances et responsables.',
            'en' => 'Fix the findings through an action plan with deadlines and owners.',
        ],
        '18.1' => [
            'essential' => true,
            'fr' => 'N\'installer et ne garder que les logiciels nécessaires (image de référence maîtrisée).',
            'en' => 'Install and keep only the software needed (controlled master image).',
        ],
        '18.2' => [
            'essential' => true,
            'fr' => 'Réduire le risque quand un logiciel inutile ne peut pas être retiré.',
            'en' => 'Reduce the risk when unneeded software cannot be removed.',
        ],
        '18.3' => [
            'essential' => true,
            'fr' => 'Configurer les ressources de façon sécurisée selon les guides de l\'éditeur, du fabricant ou '
                . 'de l\'ANSSI.',
            'en' => 'Configure resources securely following vendor, manufacturer or ANSSI guides.',
        ],
        '18.4' => [
            'essential' => true,
            'fr' => 'Revoir chaque année les configurations, de préférence avec des outils automatisés.',
            'en' => 'Review configurations every year, preferably with automated tools.',
        ],
        '19.1' => [
            'essential' => true,
            'fr' => 'Administrer au travers d\'un réseau d\'administration dédié.',
            'en' => 'Administer through a dedicated administration network.',
        ],
        '19.2' => [
            'essential' => true,
            'fr' => 'Faire gérer les ressources de ce réseau par l\'entité ou par son prestataire mandaté.',
            'en' => 'Have this network\'s resources managed by the entity or its appointed provider.',
        ],
        '19.3' => [
            'essential' => true,
            'fr' => 'Réserver le matériel du réseau d\'administration aux actions d\'administration.',
            'en' => 'Use administration network hardware only for administration.',
        ],
        '19.4' => [
            'essential' => true,
            'fr' => 'Réserver le poste physique d\'administration aux actions d\'administration.',
            'en' => 'Use the administration workstation only for administration.',
        ],
        '19.5' => [
            'essential' => true,
            'fr' => 'Se connecter au réseau d\'administration uniquement depuis un tel poste dédié.',
            'en' => 'Connect to the administration network only from such a dedicated workstation.',
        ],
        '19.6' => [
            'essential' => true,
            'fr' => 'À défaut de poste dédié, durcir et isoler l\'environnement d\'administration sur le poste.',
            'en' => 'Without a dedicated workstation, harden and isolate the administration environment on the '
                . 'device.',
        ],
        '19.7' => [
            'essential' => true,
            'fr' => 'Relier le réseau d\'administration aux ressources par un lien physique et une interface '
                . 'd\'administration dédiés.',
            'en' => 'Link the administration network to resources through a dedicated physical link and '
                . 'management interface.',
        ],
        '19.8' => [
            'essential' => true,
            'fr' => 'Appliquer au réseau d\'administration le même cloisonnement et le même filtrage qu\'aux SI.',
            'en' => 'Apply to the administration network the same segregation and filtering as to the systems.',
        ],
        '19.9' => [
            'essential' => true,
            'fr' => 'À défaut de lien physique dédié, appliquer des mesures logiques conformes aux '
                . 'recommandations de l\'ANSSI.',
            'en' => 'Without a dedicated physical link, apply logical measures in line with ANSSI recommendations.',
        ],
        '19.10' => [
            'essential' => true,
            'fr' => 'Chiffrer et authentifier les flux d\'administration selon l\'état de l\'art.',
            'en' => 'Encrypt and authenticate administration traffic using state-of-the-art means.',
        ],
        '19.11' => [
            'essential' => true,
            'fr' => 'Encapsuler les flux d\'administration qui passent par des réseaux non dédiés (tunnel VPN).',
            'en' => 'Tunnel administration traffic crossing non-dedicated networks (VPN).',
        ],
        '19.12' => [
            'essential' => true,
            'fr' => 'À défaut de chiffrement possible, protéger ces flux et renforcer le contrôle et la '
                . 'traçabilité.',
            'en' => 'When encryption is not possible, protect this traffic and strengthen control and logging.',
        ],
        '20.1' => [
            'essential' => true,
            'fr' => 'Dimensionner la supervision pour traiter journaux et événements sous 24 heures ouvrées au '
                . 'plus.',
            'en' => 'Size security monitoring to handle logs and events within 24 working hours at most.',
        ],
        '20.2' => [
            'essential' => true,
            'fr' => 'Améliorer en continu la supervision et sa couverture des scénarios de menace.',
            'en' => 'Continuously improve monitoring and its coverage of threat scenarios.',
        ],
        '20.3' => [
            'essential' => true,
            'fr' => 'Maîtriser l\'architecture et la configuration du SI de supervision (collecte, analyse, '
                . 'investigation).',
            'en' => 'Control the architecture and configuration of the monitoring system (collection, analysis, '
                . 'investigation).',
        ],
        '20.4' => [
            'essential' => true,
            'fr' => 'Collecter les journaux utiles à la détection des principaux scénarios (réseau, système, '
                . 'applicatif, utilisateur).',
            'en' => 'Collect the logs needed to detect the main scenarios (network, system, application, user).',
        ],
        '20.5' => [
            'essential' => true,
            'fr' => 'Conserver journaux et événements de sécurité au moins trois mois.',
            'en' => 'Keep security logs and events for at least three months.',
        ],
        '20.6' => [
            'essential' => true,
            'fr' => 'Protéger ces données contre un incident qui les rendrait inutilisables.',
            'en' => 'Protect this data against an incident that would make it unusable.',
        ],
    ];

    /** Numéro de l'objectif d'une mesure (« 10.B.3 » => 10), 0 si inconnue. */
    public static function objectiveOf(string $code): int
    {
        return isset(self::MEASURES[$code]) ? (int) explode('.', $code)[0] : 0;
    }

    /** Sous-partie d'une mesure (« 10.B.3 » => « 10.B »), vide si l'objectif n'en a pas. */
    public static function sectionOf(string $code): string
    {
        $parts = explode('.', $code);
        if (count($parts) !== 3) {
            return '';
        }
        $section = $parts[0] . '.' . $parts[1];

        return isset(self::SECTIONS[$section]) ? $section : '';
    }

    public static function objectiveTitle(int $objective, ?string $language = null): string
    {
        $entry = self::OBJECTIVES[$objective] ?? null;

        return $entry === null ? (string) $objective : CatalogLanguage::pick($entry, '', $language);
    }

    public static function sectionTitle(string $section, ?string $language = null): string
    {
        $entry = self::SECTIONS[$section] ?? null;

        return $entry === null ? $section : CatalogLanguage::pick($entry, '', $language);
    }

    public static function summary(string $code, ?string $language = null): string
    {
        $entry = self::MEASURES[$code] ?? null;

        return $entry === null ? '' : CatalogLanguage::pick($entry, '', $language);
    }

    public static function isEssentialOnly(string $code): bool
    {
        return !empty(self::MEASURES[$code]['essential']);
    }

    /** Numéro officiel avec son suffixe : « 2.A.2-EE », « 1.1-EI/EE ». */
    public static function reference(string $code): string
    {
        return $code . (self::isEssentialOnly($code) ? '-EE' : '-EI/EE');
    }

    /** La mesure fait-elle partie du catalogue de la catégorie d'entité ? */
    public static function inScope(string $code, string $category): bool
    {
        if (!isset(self::MEASURES[$code]) || !in_array($category, self::CATEGORIES_ENTITY, true)) {
            return false;
        }

        return $category === self::CATEGORY_ESSENTIAL || !self::isEssentialOnly($code);
    }

    /**
     * Toutes les mesures, dans l'ordre du référentiel.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map('strval', array_keys(self::MEASURES));
    }

    /**
     * Mesures du catalogue d'une catégorie d'entité, dans l'ordre du référentiel.
     *
     * @return list<string>
     */
    public static function codesFor(string $category): array
    {
        return array_values(array_filter(
            self::codes(),
            static fn (string $code): bool => self::inScope($code, $category)
        ));
    }

    /**
     * Objectifs du catalogue d'une catégorie d'entité.
     *
     * @return list<int>
     */
    public static function objectivesFor(string $category): array
    {
        $objectives = [];
        foreach (self::codesFor($category) as $code) {
            $objectives[self::objectiveOf($code)] = true;
        }

        return array_keys($objectives);
    }

    /**
     * Exigences NIS2 (UE) liées à l'objectif de la mesure, avec leur texte dans la langue retenue.
     *
     * @return array<string, string>
     */
    public static function nis2For(string $code, ?string $language = null): array
    {
        $refs = [];
        $keys = array_flip(self::OBJECTIVES[self::objectiveOf($code)]['nis2'] ?? []);
        foreach (array_intersect_key(Nis2CatalogDefaults::REQUIREMENTS, $keys) as $key => $requirement) {
            $refs[(string) $key] = CatalogLanguage::pick($requirement, '', $language);
        }

        return $refs;
    }
}

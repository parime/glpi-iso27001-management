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
 * Issue #878 : ETSI EN 319 401 V3.2.1 (2026-01), exigences générales des politiques des prestataires
 * de services de confiance (PSCo : signature, cachet, horodatage, certificats au sens d'eIDAS). Base
 * commune des normes ETSI EN 319 411, 319 421... qu'un organisme d'évaluation de la conformité audite.
 *
 * Droits : norme protégée (© ETSI 2026, reproduction interdite sans autorisation écrite). Seuls les
 * numéros de paragraphes sont repris ; leur objet est résumé avec les mots de ce projet, jamais le
 * texte des exigences (REQ-...). La correspondance `annex_a` avec ISO/IEC 27001:2022 est indicative
 * et établie par ce projet ; une liste vide signale un paragraphe porté par les chapitres 4 à 10 de
 * la norme (appréciation des risques). Mention affichée sous la carte de
 * templates/referentiels.html.twig.
 */
final class Etsi319401CatalogDefaults
{
    public const SOURCE_URL = 'https://www.etsi.org/deliver/etsi_en/319400_319499/319401/03.02.01_60/';

    /**
     * @var array<int|string, array{fr: string, en: string}> Chapitre => intitulé (mots de ce projet).
     */
    public const CLAUSES = [
        '5' => ['fr' => 'Gestion et appréciation des risques', 'en' => 'Risk management and assessment'],
        '6' => ['fr' => 'Politiques et pratiques', 'en' => 'Policies and practices'],
        '7' => ['fr' => 'Organisation et exploitation du prestataire', 'en' => 'Provider management and operation'],
    ];

    /** Modules du plugin qu'un paragraphe peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'risks', 'riskcatalog', 'policies', 'reviews', 'trainings', 'accessreviews', 'accounthygiene',
        'vulnerabilities', 'networkflows', 'incidents', 'nonconformities', 'impactanalysis', 'exercises',
        'audits', 'processing', 'suppliers',
    ];

    /**
     * Paragraphe (numéro officiel) => chapitre, objet résumé par ce projet (fr, en), mesures de
     * l'Annexe A ISO 27001 concernées et modules du plugin. La clé « 5 » est un entier en PHP.
     *
     * @var array<int|string, array{clause: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const REQUIREMENTS = [
        '5'      => [
            'clause'  => '5',
            'fr'      => 'Cadre de gestion des risques : appréciation régulière, traitement choisi, risques '
                . 'résiduels acceptés par la direction (chapitres 6.1 et 8.2 d\'ISO 27001).',
            'en'      => 'Risk management framework: regular assessment, chosen treatment, residual risks '
                . 'accepted by management (clauses 6.1 and 8.2 of ISO 27001).',
            'annex_a' => [],
            'modules' => ['risks', 'riskcatalog'],
        ],
        '6.1'    => [
            'clause'  => '6',
            'fr'      => 'Déclaration des pratiques du service de confiance, approuvée et publiée.',
            'en'      => 'Trust service practice statement, approved and published.',
            'annex_a' => ['A.5.1', 'A.5.37'],
            'modules' => ['policies'],
        ],
        '6.2'    => [
            'clause'  => '6',
            'fr'      => 'Conditions générales mises à disposition des abonnés et des tiers utilisateurs.',
            'en'      => 'Terms and conditions made available to subscribers and relying parties.',
            'annex_a' => ['A.5.31'],
            'modules' => [],
        ],
        '6.3'    => [
            'clause'  => '6',
            'fr'      => 'Politique de sécurité de l\'information et des réseaux, approuvée, communiquée et revue.',
            'en'      => 'Information and network security policy, approved, communicated and reviewed.',
            'annex_a' => ['A.5.1', 'A.5.4'],
            'modules' => ['policies', 'reviews'],
        ],
        '7.1.1'  => [
            'clause'  => '7',
            'fr'      => 'Organisation interne : rôles et responsabilités de sécurité définis.',
            'en'      => 'Internal organisation: security roles and responsibilities defined.',
            'annex_a' => ['A.5.2'],
            'modules' => [],
        ],
        '7.1.2'  => [
            'clause'  => '7',
            'fr'      => 'Fiabilité de l\'organisation : pratiques non discriminatoires, ressources '
                . 'financières et couverture de responsabilité suffisantes.',
            'en'      => 'Reliability of the organisation: non-discriminatory practices, sufficient financial '
                . 'resources and liability cover.',
            'annex_a' => ['A.5.31'],
            'modules' => [],
        ],
        '7.1.3'  => [
            'clause'  => '7',
            'fr'      => 'Séparation des tâches incompatibles.',
            'en'      => 'Segregation of conflicting duties.',
            'annex_a' => ['A.5.3'],
            'modules' => [],
        ],
        '7.2'    => [
            'clause'  => '7',
            'fr'      => 'Ressources humaines : compétences, rôles de confiance, vérifications, formation et '
                . 'sanctions.',
            'en'      => 'Human resources: skills, trusted roles, screening, training and disciplinary process.',
            'annex_a' => ['A.6.1', 'A.6.2', 'A.6.3', 'A.6.4'],
            'modules' => ['trainings'],
        ],
        '7.3.1'  => [
            'clause'  => '7',
            'fr'      => 'Inventaire des actifs et de leurs propriétaires.',
            'en'      => 'Inventory of the assets and their owners.',
            'annex_a' => ['A.5.9'],
            'modules' => [],
        ],
        '7.3.2'  => [
            'clause'  => '7',
            'fr'      => 'Classification des actifs selon l\'appréciation des risques.',
            'en'      => 'Classification of the assets according to the risk assessment.',
            'annex_a' => ['A.5.12', 'A.5.13'],
            'modules' => [],
        ],
        '7.3.3'  => [
            'clause'  => '7',
            'fr'      => 'Supports de stockage : manipulation, protection et destruction sûre.',
            'en'      => 'Storage media: handling, protection and secure disposal.',
            'annex_a' => ['A.7.10', 'A.7.14'],
            'modules' => [],
        ],
        '7.4.1'  => [
            'clause'  => '7',
            'fr'      => 'Contrôle d\'accès : accès limités aux personnes autorisées, droits revus.',
            'en'      => 'Access control: access limited to authorised people, rights reviewed.',
            'annex_a' => ['A.5.15', 'A.8.3'],
            'modules' => ['accessreviews'],
        ],
        '7.4.2'  => [
            'clause'  => '7',
            'fr'      => 'Comptes à privilèges et d\'administration système, limités et suivis.',
            'en'      => 'Privileged and system administration accounts, limited and monitored.',
            'annex_a' => ['A.8.2', 'A.5.18'],
            'modules' => ['accessreviews', 'accounthygiene'],
        ],
        '7.4.3'  => [
            'clause'  => '7',
            'fr'      => 'Systèmes d\'administration dédiés et protégés.',
            'en'      => 'Dedicated and protected administration systems.',
            'annex_a' => ['A.8.2', 'A.8.22'],
            'modules' => [],
        ],
        '7.4.4'  => [
            'clause'  => '7',
            'fr'      => 'Identification unique des utilisateurs.',
            'en'      => 'Unique identification of users.',
            'annex_a' => ['A.5.16'],
            'modules' => ['accounthygiene'],
        ],
        '7.4.5'  => [
            'clause'  => '7',
            'fr'      => 'Authentification et gestion des secrets.',
            'en'      => 'Authentication and management of secrets.',
            'annex_a' => ['A.5.17', 'A.8.5'],
            'modules' => ['accounthygiene'],
        ],
        '7.4.6'  => [
            'clause'  => '7',
            'fr'      => 'Authentification à plusieurs facteurs.',
            'en'      => 'Multi-factor authentication.',
            'annex_a' => ['A.8.5'],
            'modules' => [],
        ],
        '7.5'    => [
            'clause'  => '7',
            'fr'      => 'Mesures cryptographiques : cycle de vie des clés et modules cryptographiques.',
            'en'      => 'Cryptographic controls: key life cycle and cryptographic modules.',
            'annex_a' => ['A.8.24'],
            'modules' => [],
        ],
        '7.6'    => [
            'clause'  => '7',
            'fr'      => 'Sécurité physique et environnementale des sites et équipements.',
            'en'      => 'Physical and environmental security of sites and equipment.',
            'annex_a' => ['A.7.1', 'A.7.2', 'A.7.4', 'A.7.5', 'A.7.11'],
            'modules' => [],
        ],
        '7.7'    => [
            'clause'  => '7',
            'fr'      => 'Sécurité de l\'exploitation : changements, capacités, logiciels malveillants, '
                . 'correctifs.',
            'en'      => 'Operations security: changes, capacity, malware, patches.',
            'annex_a' => ['A.8.6', 'A.8.7', 'A.8.8', 'A.8.32'],
            'modules' => ['vulnerabilities'],
        ],
        '7.8'    => [
            'clause'  => '7',
            'fr'      => 'Sécurité des réseaux : zones, filtrage, durcissement, tests d\'intrusion et '
                . 'recherche de vulnérabilités.',
            'en'      => 'Network security: zones, filtering, hardening, penetration tests and vulnerability '
                . 'scans.',
            'annex_a' => ['A.8.20', 'A.8.21', 'A.8.22'],
            'modules' => ['networkflows', 'vulnerabilities'],
        ],
        '7.9.1'  => [
            'clause'  => '7',
            'fr'      => 'Surveillance et journalisation des activités.',
            'en'      => 'Monitoring and logging of activities.',
            'annex_a' => ['A.8.15', 'A.8.16'],
            'modules' => [],
        ],
        '7.9.2'  => [
            'clause'  => '7',
            'fr'      => 'Réponse aux incidents, organisée et rapide.',
            'en'      => 'Organised and timely incident response.',
            'annex_a' => ['A.5.24', 'A.5.26'],
            'modules' => ['incidents'],
        ],
        '7.9.3'  => [
            'clause'  => '7',
            'fr'      => 'Notification des incidents à l\'autorité de contrôle et aux personnes concernées dans '
                . 'les délais.',
            'en'      => 'Notification of incidents to the supervisory body and the affected parties on time.',
            'annex_a' => ['A.5.5', 'A.6.8'],
            'modules' => ['incidents'],
        ],
        '7.9.4'  => [
            'clause'  => '7',
            'fr'      => 'Évaluation et classification des événements de sécurité.',
            'en'      => 'Assessment and classification of security events.',
            'annex_a' => ['A.5.25'],
            'modules' => ['incidents'],
        ],
        '7.9.5'  => [
            'clause'  => '7',
            'fr'      => 'Retour d\'expérience après incident.',
            'en'      => 'Post-incident review.',
            'annex_a' => ['A.5.27'],
            'modules' => ['incidents', 'nonconformities'],
        ],
        '7.10'   => [
            'clause'  => '7',
            'fr'      => 'Conservation des preuves et des enregistrements, horodatés, pendant la durée prévue.',
            'en'      => 'Retention of time-stamped evidence and records for the planned period.',
            'annex_a' => ['A.5.28', 'A.5.33', 'A.8.17'],
            'modules' => [],
        ],
        '7.11.1' => [
            'clause'  => '7',
            'fr'      => 'Plan de continuité d\'activité, y compris la compromission des clés.',
            'en'      => 'Business continuity plan, including key compromise.',
            'annex_a' => ['A.5.29', 'A.5.30'],
            'modules' => ['impactanalysis', 'exercises'],
        ],
        '7.11.2' => [
            'clause'  => '7',
            'fr'      => 'Sauvegardes des données nécessaires à la reprise.',
            'en'      => 'Backups of the data needed for recovery.',
            'annex_a' => ['A.8.13'],
            'modules' => [],
        ],
        '7.11.3' => [
            'clause'  => '7',
            'fr'      => 'Gestion de crise.',
            'en'      => 'Crisis management.',
            'annex_a' => ['A.5.29'],
            'modules' => ['exercises'],
        ],
        '7.12'   => [
            'clause'  => '7',
            'fr'      => 'Plan de cessation d\'activité : information des parties, transfert ou conservation des '
                . 'données.',
            'en'      => 'Termination plan: informing the parties, transfer or retention of the data.',
            'annex_a' => ['A.5.33'],
            'modules' => [],
        ],
        '7.13'   => [
            'clause'  => '7',
            'fr'      => 'Conformité aux exigences légales, dont la protection des données personnelles.',
            'en'      => 'Compliance with legal requirements, including personal data protection.',
            'annex_a' => ['A.5.31', 'A.5.34', 'A.5.36'],
            'modules' => ['audits', 'processing'],
        ],
        '7.14.1' => [
            'clause'  => '7',
            'fr'      => 'Politique de sécurité de la chaîne d\'approvisionnement.',
            'en'      => 'Supply chain security policy.',
            'annex_a' => ['A.5.19'],
            'modules' => ['suppliers'],
        ],
        '7.14.2' => [
            'clause'  => '7',
            'fr'      => 'Procédures de sélection et de suivi des fournisseurs.',
            'en'      => 'Procedures to select and follow up the suppliers.',
            'annex_a' => ['A.5.20', 'A.5.21', 'A.5.22'],
            'modules' => ['suppliers'],
        ],
        '7.14.3' => [
            'clause'  => '7',
            'fr'      => 'Responsabilités, accords avec les tiers et niveaux de service.',
            'en'      => 'Responsibilities, third party agreements and service levels.',
            'annex_a' => ['A.5.20', 'A.5.23'],
            'modules' => ['suppliers'],
        ],
    ];

    /**
     * Paragraphes d'ETSI EN 319 401 reliés à une mesure de l'Annexe A ISO 27001.
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
}

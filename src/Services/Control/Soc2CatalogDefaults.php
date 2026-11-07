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
 * Issue #379 : SOC 2, rapport d'assurance fondé sur les Trust Services Criteria (TSC 2017, points of
 * focus révisés en 2022) de l'AICPA. Neuf séries de critères communs (CC1 à CC9, la sécurité, toujours
 * dans le périmètre) et des critères complémentaires optionnels : disponibilité (A1), confidentialité
 * (C1), intégrité du traitement (PI1) et vie privée (P1 à P8, regroupés ici en une ligne).
 *
 * Droits : document protégé (© AICPA). Seuls les identifiants des séries de critères sont repris ;
 * leur objet est résumé avec les mots de ce projet, jamais le texte des critères. La correspondance
 * `annex_a` avec ISO/IEC 27001:2022 est indicative et établie par ce projet (elle ne reprend pas la
 * table de correspondance publiée par l'AICPA). Mention affichée sous la carte SOC 2 de
 * templates/referentiels.html.twig.
 */
final class Soc2CatalogDefaults
{
    public const SOURCE_URL = 'https://www.aicpa-cima.com/topic/audit-assurance/audit-and-assurance-greater-than-soc-2';

    /**
     * @var array<string, array{fr: string, en: string}> Catégorie => intitulé (mots de ce projet).
     */
    public const CATEGORIES = [
        'common'     => ['fr' => 'Critères communs (sécurité)', 'en' => 'Common criteria (security)'],
        'additional' => ['fr' => 'Critères complémentaires', 'en' => 'Additional criteria'],
    ];

    /** Modules du plugin qu'un critère peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'policies', 'trainings', 'risks', 'audits', 'nonconformities', 'accessreviews', 'accounthygiene',
        'vulnerabilities', 'incidents', 'suppliers', 'exercises', 'impactanalysis', 'processing', 'requests',
        'dpia',
    ];

    /**
     * Série de critères => catégorie, objet résumé par ce projet (fr, en), mesures de l'Annexe A
     * ISO 27001 concernées et modules du plugin qui y répondent.
     *
     * @var array<string, array{category: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const CRITERIA = [
        'CC1' => [
            'category' => 'common',
            'fr'       => 'Environnement de contrôle : intégrité, supervision par la direction, organisation, '
                . 'compétences et responsabilités.',
            'en'       => 'Control environment: integrity, oversight by management, organisation, skills and '
                . 'accountability.',
            'annex_a'  => ['A.5.1', 'A.5.2', 'A.5.4', 'A.6.1', 'A.6.2', 'A.6.4'],
            'modules'  => ['policies', 'trainings'],
        ],
        'CC2' => [
            'category' => 'common',
            'fr'       => 'Information et communication : faire connaître objectifs, responsabilités et moyens '
                . 'de signalement, en interne comme à l\'extérieur.',
            'en'       => 'Information and communication: make objectives, responsibilities and reporting channels '
                . 'known, internally and externally.',
            'annex_a'  => ['A.5.1', 'A.6.3', 'A.6.8'],
            'modules'  => ['policies'],
        ],
        'CC3' => [
            'category' => 'common',
            'fr'       => 'Appréciation des risques, y compris la fraude et les changements importants.',
            'en'       => 'Risk assessment, fraud and significant changes included.',
            'annex_a'  => ['A.5.7'],
            'modules'  => ['risks'],
        ],
        'CC4' => [
            'category' => 'common',
            'fr'       => 'Surveillance : évaluer régulièrement les contrôles et traiter les écarts constatés.',
            'en'       => 'Monitoring: regularly evaluate the controls and handle the deficiencies found.',
            'annex_a'  => ['A.5.35', 'A.5.36'],
            'modules'  => ['audits', 'nonconformities'],
        ],
        'CC5' => [
            'category' => 'common',
            'fr'       => 'Activités de contrôle : des contrôles choisis contre les risques, décrits dans des '
                . 'politiques et procédures.',
            'en'       => 'Control activities: controls chosen against the risks, described in policies and '
                . 'procedures.',
            'annex_a'  => ['A.5.1', 'A.5.37'],
            'modules'  => ['policies'],
        ],
        'CC6' => [
            'category' => 'common',
            'fr'       => 'Accès logiques et physiques : identification, attribution et retrait des droits, '
                . 'protection des données et des locaux.',
            'en'       => 'Logical and physical access: identification, granting and removal of rights, protection '
                . 'of data and premises.',
            'annex_a'  => ['A.5.15', 'A.5.18', 'A.8.2', 'A.8.5', 'A.7.2', 'A.8.24'],
            'modules'  => ['accessreviews', 'accounthygiene'],
        ],
        'CC7' => [
            'category' => 'common',
            'fr'       => 'Exploitation : détecter vulnérabilités et anomalies, répondre aux incidents et '
                . 'rétablir le service.',
            'en'       => 'Operations: detect vulnerabilities and anomalies, respond to incidents and restore '
                . 'service.',
            'annex_a'  => ['A.8.8', 'A.8.16', 'A.5.24', 'A.5.26'],
            'modules'  => ['vulnerabilities', 'incidents'],
        ],
        'CC8' => [
            'category' => 'common',
            'fr'       => 'Gestion des changements : autoriser, tester et approuver les modifications avant mise '
                . 'en production.',
            'en'       => 'Change management: authorise, test and approve changes before going live.',
            'annex_a'  => ['A.8.32'],
            'modules'  => [],
        ],
        'CC9' => [
            'category' => 'common',
            'fr'       => 'Atténuation des risques : interruptions d\'activité et risques liés aux fournisseurs '
                . 'et partenaires.',
            'en'       => 'Risk mitigation: business disruptions and risks from suppliers and partners.',
            'annex_a'  => ['A.5.19', 'A.5.21', 'A.5.22', 'A.5.30'],
            'modules'  => ['suppliers', 'risks'],
        ],
        'A1' => [
            'category' => 'additional',
            'fr'       => 'Disponibilité : capacité, sauvegardes et tests de reprise.',
            'en'       => 'Availability: capacity, backups and recovery tests.',
            'annex_a'  => ['A.8.6', 'A.8.13', 'A.8.14', 'A.5.30'],
            'modules'  => ['impactanalysis', 'exercises'],
        ],
        'C1' => [
            'category' => 'additional',
            'fr'       => 'Confidentialité : repérer les informations confidentielles, les protéger et les '
                . 'détruire en fin de conservation.',
            'en'       => 'Confidentiality: identify confidential information, protect it and destroy it when '
                . 'retention ends.',
            'annex_a'  => ['A.5.12', 'A.5.13', 'A.8.10'],
            'modules'  => [],
        ],
        'PI1' => [
            'category' => 'additional',
            'fr'       => 'Intégrité du traitement : des traitements complets, exacts, à temps et autorisés.',
            'en'       => 'Processing integrity: complete, accurate, timely and authorised processing.',
            'annex_a'  => ['A.8.26'],
            'modules'  => [],
        ],
        'P' => [
            'category' => 'additional',
            'fr'       => 'Vie privée (P1 à P8) : information, consentement, collecte, conservation, accès, '
                . 'communication, qualité et suivi des données personnelles.',
            'en'       => 'Privacy (P1 to P8): notice, consent, collection, retention, access, disclosure, quality '
                . 'and monitoring of personal data.',
            'annex_a'  => ['A.5.34'],
            'modules'  => ['processing', 'requests', 'dpia'],
        ],
    ];

    /**
     * Séries de critères SOC 2 reliées à une mesure de l'Annexe A ISO 27001.
     *
     * @return list<string>
     */
    public static function criteriaForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::CRITERIA as $code => $criterion) {
            if (in_array($annexACode, $criterion['annex_a'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

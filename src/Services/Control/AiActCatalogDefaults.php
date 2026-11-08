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
 * Issue #379 : règlement (UE) 2024/1689 sur l'intelligence artificielle (« AI Act »), dans sa
 * version consolidée du 27/07/2026 qui intègre le règlement (UE) 2026/1744 (« omnibus numérique
 * sur l'IA ») : article 4 assoupli (favoriser la maîtrise de l'IA, sans niveau imposé), application
 * des obligations des systèmes à haut risque reportée au 2/12/2027 (annexe III) et au 2/8/2028
 * (annexe I), nouvelles interdictions de l'article 5 applicables au 2/12/2026.
 *
 * Droits : texte législatif de l'Union publié sur EUR-Lex, réutilisation autorisée sous réserve de
 * mentionner la source (décision 2011/833/UE). Les intitulés d'articles sont ceux des versions
 * officielles française et anglaise ; les obligations sont résumées par ce projet. Seule la
 * version publiée au Journal officiel de l'UE fait foi. Correspondance `annex_a` indicative,
 * établie par ce projet.
 */
final class AiActCatalogDefaults
{
    public const SOURCE_URL = 'https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:02024R1689-20260727';

    /**
     * @var array<string, array{fr: string, en: string}> Rôle visé (regroupement de ce projet).
     */
    public const ROLES = [
        'all'      => [
            'fr' => 'Toute organisation qui fournit ou utilise de l\'IA',
            'en' => 'Any organisation providing or using AI',
        ],
        'deployer' => ['fr' => 'Déployeurs (utilisateurs professionnels)', 'en' => 'Deployers (professional users)'],
        'provider' => [
            'fr' => 'Fournisseurs de systèmes d\'IA à haut risque',
            'en' => 'Providers of high-risk AI systems',
        ],
    ];

    /** Modules du plugin qu'un article peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'trainings', 'riskcatalog', 'risks', 'processing', 'dpia', 'incidents', 'suppliers', 'policies',
    ];

    /**
     * Article => rôle, intitulé officiel (fr, en), obligation résumée par ce projet (fr, en), date
     * d'application (article 113 consolidé), mesures de l'Annexe A ISO 27001 et modules du plugin.
     * Clés numériques, donc int en PHP.
     *
     * @var array<int|string, array{role: string, title_fr: string, title_en: string, fr: string, en: string,
     *     applies: string, annex_a: list<string>, modules: list<string>}>
     */
    public const ARTICLES = [
        '4' => [
            'role'     => 'all',
            'title_fr' => 'Maîtrise de l\'IA',
            'title_en' => 'AI literacy',
            'fr'       => 'Prendre des mesures qui favorisent la maîtrise de l\'IA du personnel et des personnes qui '
                . 'utilisent les systèmes d\'IA pour le compte de l\'organisation (aucun niveau imposé depuis 2026).',
            'en'       => 'Take measures that foster the AI literacy of staff and of people using AI systems on the '
                . 'organisation\'s behalf (no specific level required since 2026).',
            'applies'  => '2025-02-02',
            'annex_a'  => ['A.6.3', 'A.5.2'],
            'modules'  => ['trainings'],
        ],
        '5' => [
            'role'     => 'all',
            'title_fr' => 'Pratiques interdites en matière d\'IA',
            'title_en' => 'Prohibited AI practices',
            'fr'       => 'N\'utiliser aucune pratique interdite (manipulation, notation sociale, certaines '
                . 'reconnaissances biométriques...) ; les interdictions ajoutées en 2026 s\'appliquent au 2/12/2026.',
            'en'       => 'Use no prohibited practice (manipulation, social scoring, some biometric '
                . 'recognition...); the prohibitions added in 2026 apply from 2 December 2026.',
            'applies'  => '2025-02-02',
            'annex_a'  => ['A.5.10', 'A.5.31'],
            'modules'  => ['riskcatalog'],
        ],
        '6' => [
            'role'     => 'all',
            'title_fr' => 'Règles relatives à la classification de systèmes d\'IA comme systèmes à haut risque',
            'title_en' => 'Classification rules for high-risk AI systems',
            'fr'       => 'Recenser les systèmes d\'IA et déterminer lesquels sont à haut risque (annexe III : '
                . 'recrutement, crédit, éducation, infrastructures critiques...).',
            'en'       => 'Inventory the AI systems and determine which are high-risk (Annex III: recruitment, '
                . 'credit, education, critical infrastructure...).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.9', 'A.5.12'],
            'modules'  => ['riskcatalog'],
        ],
        '50' => [
            'role'     => 'all',
            'title_fr' => 'Obligations de transparence pour les fournisseurs et les déployeurs de certains '
                . 'systèmes d\'IA',
            'title_en' => 'Transparency obligations for providers and deployers of certain AI systems',
            'fr'       => 'Informer les personnes qu\'elles échangent avec une IA, marquer les contenus générés et '
                . 'signaler les hypertrucages ; systèmes déjà sur le marché : marquage au 2/12/2026.',
            'en'       => 'Tell people they are interacting with an AI, mark generated content and disclose deep '
                . 'fakes; systems already on the market: marking by 2 December 2026.',
            'applies'  => '2026-08-02',
            'annex_a'  => ['A.5.10', 'A.5.34'],
            'modules'  => ['policies'],
        ],
        '26' => [
            'role'     => 'deployer',
            'title_fr' => 'Obligations incombant aux déployeurs de systèmes d\'IA à haut risque',
            'title_en' => 'Obligations of deployers of high-risk AI systems',
            'fr'       => 'Utiliser le système selon sa notice, confier le contrôle humain à des personnes '
                . 'compétentes, surveiller son fonctionnement, garder les journaux au moins six mois.',
            'en'       => 'Use the system according to its instructions, give human oversight to competent people, '
                . 'monitor its operation, keep the logs for at least six months.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.10', 'A.6.3', 'A.8.15', 'A.8.16'],
            'modules'  => ['trainings', 'suppliers'],
        ],
        '27' => [
            'role'     => 'deployer',
            'title_fr' => 'Analyse d\'impact des systèmes d\'IA à haut risque sur les droits fondamentaux',
            'title_en' => 'Fundamental rights impact assessment for high-risk AI systems',
            'fr'       => 'Organismes publics, services publics et certains déployeurs de l\'annexe III : analyser '
                . 'l\'impact sur les droits fondamentaux avant la mise en service (complète l\'AIPD).',
            'en'       => 'Public bodies, public services and some Annex III deployers: assess the impact on '
                . 'fundamental rights before putting into service (complements the DPIA).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.34'],
            'modules'  => ['dpia'],
        ],
        '9' => [
            'role'     => 'provider',
            'title_fr' => 'Système de gestion des risques',
            'title_en' => 'Risk management system',
            'fr'       => 'Gérer les risques du système d\'IA tout au long de son cycle de vie, tests compris.',
            'en'       => 'Manage the risks of the AI system throughout its life cycle, testing included.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.8', 'A.8.25'],
            'modules'  => ['risks'],
        ],
        '10' => [
            'role'     => 'provider',
            'title_fr' => 'Données et gouvernance des données',
            'title_en' => 'Data and data governance',
            'fr'       => 'Maîtriser les données d\'entraînement, de validation et de test : qualité, '
                . 'représentativité, biais.',
            'en'       => 'Control training, validation and test data: quality, representativeness, bias.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.12', 'A.5.33', 'A.5.34'],
            'modules'  => ['processing', 'dpia'],
        ],
        '11' => [
            'role'     => 'provider',
            'title_fr' => 'Documentation technique',
            'title_en' => 'Technical documentation',
            'fr'       => 'Établir et tenir à jour la documentation technique avant la mise sur le marché.',
            'en'       => 'Draw up and keep up to date the technical documentation before placing on the market.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.37'],
            'modules'  => [],
        ],
        '12' => [
            'role'     => 'provider',
            'title_fr' => 'Enregistrement',
            'title_en' => 'Record-keeping',
            'fr'       => 'Journaliser automatiquement les événements du système pour la traçabilité.',
            'en'       => 'Automatically log the system\'s events for traceability.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.8.15'],
            'modules'  => [],
        ],
        '13' => [
            'role'     => 'provider',
            'title_fr' => 'Transparence et fourniture d\'informations aux déployeurs',
            'title_en' => 'Transparency and provision of information to deployers',
            'fr'       => 'Fournir aux déployeurs une notice d\'utilisation claire (capacités, limites, contrôle '
                . 'humain).',
            'en'       => 'Give deployers clear instructions for use (capabilities, limits, human oversight).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.37'],
            'modules'  => [],
        ],
        '14' => [
            'role'     => 'provider',
            'title_fr' => 'Contrôle humain',
            'title_en' => 'Human oversight',
            'fr'       => 'Concevoir le système pour qu\'une personne puisse le surveiller et l\'arrêter.',
            'en'       => 'Design the system so that a person can oversee it and stop it.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.2', 'A.8.16'],
            'modules'  => [],
        ],
        '15' => [
            'role'     => 'provider',
            'title_fr' => 'Exactitude, robustesse et cybersécurité',
            'title_en' => 'Accuracy, robustness and cybersecurity',
            'fr'       => 'Atteindre un niveau d\'exactitude, de robustesse et de cybersécurité approprié, résister '
                . 'aux attaques propres à l\'IA (empoisonnement, contournement).',
            'en'       => 'Reach an appropriate level of accuracy, robustness and cybersecurity, resist AI-specific '
                . 'attacks (poisoning, evasion).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.8.8', 'A.8.25', 'A.8.29'],
            'modules'  => ['riskcatalog'],
        ],
        '17' => [
            'role'     => 'provider',
            'title_fr' => 'Système de gestion de la qualité',
            'title_en' => 'Quality management system',
            'fr'       => 'Mettre en place un système de gestion de la qualité documenté (politiques, procédures, '
                . 'contrôles).',
            'en'       => 'Set up a documented quality management system (policies, procedures, controls).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.1', 'A.5.36'],
            'modules'  => ['policies'],
        ],
        '72' => [
            'role'     => 'provider',
            'title_fr' => 'Surveillance après commercialisation',
            'title_en' => 'Post-market monitoring',
            'fr'       => 'Surveiller le système une fois sur le marché, selon un plan documenté.',
            'en'       => 'Monitor the system once on the market, following a documented plan.',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.36', 'A.8.16'],
            'modules'  => [],
        ],
        '73' => [
            'role'     => 'provider',
            'title_fr' => 'Signalement d\'incidents graves',
            'title_en' => 'Reporting of serious incidents',
            'fr'       => 'Signaler un incident grave à l\'autorité dans les 15 jours (2 jours si infraction '
                . 'généralisée ou infrastructure critique, 10 jours en cas de décès).',
            'en'       => 'Report a serious incident to the authority within 15 days (2 days for a widespread '
                . 'infringement or critical infrastructure, 10 days in case of death).',
            'applies'  => '2027-12-02',
            'annex_a'  => ['A.5.24', 'A.5.26', 'A.6.8'],
            'modules'  => ['incidents'],
        ],
    ];

    /**
     * Articles reliés à une mesure de l'Annexe A ISO 27001.
     *
     * @return list<string>
     */
    public static function articlesForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::ARTICLES as $code => $article) {
            if (in_array($annexACode, $article['annex_a'], true)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}

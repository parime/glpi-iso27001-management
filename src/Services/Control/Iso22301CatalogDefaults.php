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
 * Issue #379 : ISO 22301:2019, système de management de la continuité d'activité (SMCA). Même
 * structure de haut niveau qu'ISO/IEC 27001 (articles 4 à 10) ; l'essentiel du métier est à
 * l'article 8 (analyse d'impact, stratégies, plans, exercices).
 *
 * Droits : document protégé (© ISO 2019, reproduction interdite sans licence). Seuls les numéros
 * d'articles sont repris ; leur objet est résumé avec les mots de ce projet, jamais le texte de la
 * norme. La correspondance `annex_a` avec ISO/IEC 27001:2022 est indicative et établie par ce projet.
 * Mention affichée sous la carte ISO 22301 de templates/referentiels.html.twig.
 */
final class Iso22301CatalogDefaults
{
    public const SOURCE_URL = 'https://www.iso.org/standard/75106.html';

    /**
     * @var array<int, array{fr: string, en: string}> Article => thème (mots de ce projet) ; clés
     * numériques, donc int en PHP.
     */
    public const CLAUSES = [
        '4'  => ['fr' => 'Contexte et périmètre', 'en' => 'Context and scope'],
        '5'  => ['fr' => 'Engagement de la direction', 'en' => 'Management commitment'],
        '6'  => ['fr' => 'Objectifs de continuité', 'en' => 'Continuity objectives'],
        '7'  => ['fr' => 'Moyens, compétences et communication', 'en' => 'Resources, skills and communication'],
        '8'  => ['fr' => 'Continuité en pratique', 'en' => 'Continuity in practice'],
        '9'  => ['fr' => 'Mesure, audit et revue', 'en' => 'Measurement, audit and review'],
        '10' => ['fr' => 'Amélioration', 'en' => 'Improvement'],
    ];

    /** Modules du plugin qu'une exigence peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = ['risks', 'exercises', 'audits', 'reviews', 'incidents', 'suppliers'];

    /**
     * Article => thème, objet résumé par ce projet (fr, en), mesures de l'Annexe A ISO 27001
     * concernées et modules du plugin qui y répondent.
     *
     * @var array<string, array{clause: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const REQUIREMENTS = [
        '4.1' => [
            'clause'  => '4',
            'fr'      => 'Connaître l\'organisation, ses activités, ses dépendances et ce qui peut les interrompre.',
            'en'      => 'Know the organisation, its activities, its dependencies and what could disrupt them.',
            'annex_a' => ['A.5.9', 'A.5.29'],
            'modules' => ['risks'],
        ],
        '4.2' => [
            'clause'  => '4',
            'fr'      => 'Recenser les attentes des parties intéressées et les obligations légales et '
                . 'contractuelles de continuité.',
            'en'      => 'List the expectations of interested parties and the legal and contractual continuity '
                . 'obligations.',
            'annex_a' => ['A.5.31', 'A.5.5'],
            'modules' => [],
        ],
        '5.2' => [
            'clause'  => '5',
            'fr'      => 'Une politique de continuité approuvée par la direction et communiquée.',
            'en'      => 'A continuity policy approved by management and communicated.',
            'annex_a' => ['A.5.1', 'A.5.4'],
            'modules' => [],
        ],
        '5.3' => [
            'clause'  => '5',
            'fr'      => 'Des rôles et responsabilités de continuité attribués, avec leurs suppléants.',
            'en'      => 'Continuity roles and responsibilities assigned, with their deputies.',
            'annex_a' => ['A.5.2'],
            'modules' => [],
        ],
        '6.2' => [
            'clause'  => '6',
            'fr'      => 'Des objectifs de continuité mesurables et un plan pour les atteindre.',
            'en'      => 'Measurable continuity objectives and a plan to reach them.',
            'annex_a' => ['A.5.30'],
            'modules' => [],
        ],
        '7.2' => [
            'clause'  => '7',
            'fr'      => 'Des personnes compétentes et sensibilisées à leur rôle en cas de crise.',
            'en'      => 'Competent people, aware of their role in a crisis.',
            'annex_a' => ['A.6.3'],
            'modules' => ['exercises'],
        ],
        '7.4' => [
            'clause'  => '7',
            'fr'      => 'Savoir qui prévenir et comment communiquer, en interne comme à l\'extérieur, pendant '
                . 'une interruption.',
            'en'      => 'Know whom to warn and how to communicate, internally and externally, during a '
                . 'disruption.',
            'annex_a' => ['A.5.5', 'A.5.6', 'A.5.24'],
            'modules' => ['incidents'],
        ],
        '8.2' => [
            'clause'  => '8',
            'fr'      => 'Analyse d\'impact sur l\'activité (délais et pertes de données tolérables) et '
                . 'appréciation des risques d\'interruption.',
            'en'      => 'Business impact analysis (tolerable downtime and data loss) and assessment of '
                . 'disruption risks.',
            'annex_a' => ['A.5.29', 'A.5.30'],
            'modules' => ['risks'],
        ],
        '8.3' => [
            'clause'  => '8',
            'fr'      => 'Choisir les solutions de continuité : redondance, sauvegarde, site de repli, '
                . 'fournisseurs alternatifs.',
            'en'      => 'Choose continuity solutions: redundancy, backup, fallback site, alternative suppliers.',
            'annex_a' => ['A.5.30', 'A.8.13', 'A.8.14', 'A.5.21'],
            'modules' => ['suppliers'],
        ],
        '8.4' => [
            'clause'  => '8',
            'fr'      => 'Des plans écrits de réponse, de continuité et de reprise, avec une cellule de crise '
                . 'et ses procédures d\'alerte.',
            'en'      => 'Written response, continuity and recovery plans, with a crisis team and its alert '
                . 'procedures.',
            'annex_a' => ['A.5.24', 'A.5.26', 'A.5.29', 'A.5.37'],
            'modules' => ['incidents'],
        ],
        '8.5' => [
            'clause'  => '8',
            'fr'      => 'Un programme d\'exercices qui met les plans à l\'épreuve et en tire des '
                . 'enseignements.',
            'en'      => 'An exercise programme that puts the plans to the test and draws lessons from it.',
            'annex_a' => ['A.5.30', 'A.5.27'],
            'modules' => ['exercises'],
        ],
        '8.6' => [
            'clause'  => '8',
            'fr'      => 'Vérifier régulièrement que plans et capacités restent adaptés, y compris chez les '
                . 'fournisseurs.',
            'en'      => 'Check regularly that plans and capabilities remain fit, suppliers included.',
            'annex_a' => ['A.5.22', 'A.5.30'],
            'modules' => ['exercises', 'suppliers'],
        ],
        '9.2' => [
            'clause'  => '9',
            'fr'      => 'Auditer le système de continuité à intervalles planifiés.',
            'en'      => 'Audit the continuity management system at planned intervals.',
            'annex_a' => ['A.5.35'],
            'modules' => ['audits'],
        ],
        '9.3' => [
            'clause'  => '9',
            'fr'      => 'Revue de direction : résultats des exercices, incidents et évolutions décidées.',
            'en'      => 'Management review: exercise results, incidents and decided changes.',
            'annex_a' => ['A.5.35', 'A.5.36'],
            'modules' => ['reviews'],
        ],
        '10.1' => [
            'clause'  => '10',
            'fr'      => 'Traiter les écarts et les causes des interruptions par des actions correctives.',
            'en'      => 'Handle nonconformities and the causes of disruptions with corrective actions.',
            'annex_a' => ['A.5.27'],
            'modules' => ['incidents', 'audits'],
        ],
    ];

    /**
     * Articles ISO 22301 reliés à une mesure de l'Annexe A ISO 27001.
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
}

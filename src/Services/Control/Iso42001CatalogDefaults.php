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
 * Issue #379 : ISO/IEC 42001:2023, système de management de l'intelligence artificielle (SMIA).
 * Même structure de haut niveau qu'ISO/IEC 27001 ; son Annexe A (normative) range ses mesures en
 * neuf groupes, A.2 à A.10. ISO/IEC 27090 (déjà au catalogue) en couvre le volet sécurité.
 *
 * Droits : document protégé (© ISO/IEC 2023, reproduction interdite sans licence). Seuls les numéros
 * des groupes de l'Annexe A sont repris, pas les mesures une à une ; leur objet est résumé avec les
 * mots de ce projet, jamais le texte de la norme. Les thèmes qui regroupent ces numéros sont propres
 * au projet. La correspondance `annex_a` avec ISO/IEC 27001:2022 est indicative et établie par ce
 * projet. Mention affichée sous la carte ISO/IEC 42001 de templates/referentiels.html.twig.
 */
final class Iso42001CatalogDefaults
{
    public const SOURCE_URL = 'https://www.iso.org/standard/81230.html';

    /**
     * @var array<string, array{fr: string, en: string}> Thème (regroupement de ce projet) => intitulé.
     */
    public const THEMES = [
        'organisation' => ['fr' => 'Cadre, organisation et moyens', 'en' => 'Framework, organisation and resources'],
        'system'       => ['fr' => 'Impacts, cycle de vie et données', 'en' => 'Impacts, life cycle and data'],
        'use'          => ['fr' => 'Utilisation et parties prenantes', 'en' => 'Use and stakeholders'],
    ];

    /** Modules du plugin qu'un groupe peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = ['riskcatalog', 'risks', 'dpia', 'processing', 'incidents', 'suppliers'];

    /**
     * Groupe de l'Annexe A => thème, objet résumé par ce projet (fr, en), mesures de l'Annexe A
     * ISO 27001 concernées et modules du plugin qui y répondent.
     *
     * @var array<string, array{theme: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const OBJECTIVES = [
        'A.2' => [
            'theme'   => 'organisation',
            'fr'      => 'Une politique d\'IA approuvée, cohérente avec les autres politiques et revue '
                . 'régulièrement.',
            'en'      => 'An approved AI policy, consistent with the other policies and regularly reviewed.',
            'annex_a' => ['A.5.1'],
            'modules' => [],
        ],
        'A.3' => [
            'theme'   => 'organisation',
            'fr'      => 'Des rôles et responsabilités définis pour l\'IA et un moyen de signaler les '
                . 'préoccupations.',
            'en'      => 'Defined AI roles and responsibilities and a way to report concerns.',
            'annex_a' => ['A.5.2', 'A.6.8'],
            'modules' => [],
        ],
        'A.4' => [
            'theme'   => 'organisation',
            'fr'      => 'Recenser les ressources de chaque système d\'IA : données, outils, puissance de calcul '
                . 'et compétences.',
            'en'      => 'List the resources of each AI system: data, tools, computing power and skills.',
            'annex_a' => ['A.5.9', 'A.6.3'],
            'modules' => [],
        ],
        'A.5' => [
            'theme'   => 'system',
            'fr'      => 'Évaluer et documenter les impacts d\'un système d\'IA sur les personnes, les groupes '
                . 'et la société.',
            'en'      => 'Assess and document the impacts of an AI system on individuals, groups and society.',
            'annex_a' => ['A.5.34'],
            'modules' => ['dpia', 'riskcatalog'],
        ],
        'A.6' => [
            'theme'   => 'system',
            'fr'      => 'Maîtriser le cycle de vie : exigences, conception, vérification, déploiement, '
                . 'surveillance en fonctionnement, documentation et journaux.',
            'en'      => 'Control the life cycle: requirements, design, verification, deployment, monitoring '
                . 'in operation, documentation and logs.',
            'annex_a' => ['A.8.25', 'A.8.27', 'A.8.29', 'A.8.32', 'A.8.15', 'A.8.16'],
            'modules' => ['risks'],
        ],
        'A.7' => [
            'theme'   => 'system',
            'fr'      => 'Maîtriser les données de l\'IA : origine, qualité, préparation et traçabilité.',
            'en'      => 'Control the data of AI: origin, quality, preparation and traceability.',
            'annex_a' => ['A.5.12', 'A.5.33', 'A.5.34'],
            'modules' => ['processing'],
        ],
        'A.8' => [
            'theme'   => 'use',
            'fr'      => 'Informer utilisateurs et parties intéressées : documentation, signalement et '
                . 'communication des incidents.',
            'en'      => 'Inform users and interested parties: documentation, reporting and communication of '
                . 'incidents.',
            'annex_a' => ['A.5.24', 'A.5.26', 'A.5.37'],
            'modules' => ['incidents'],
        ],
        'A.9' => [
            'theme'   => 'use',
            'fr'      => 'Encadrer l\'utilisation des systèmes d\'IA : usages prévus, objectifs et règles '
                . 'd\'emploi responsable.',
            'en'      => 'Frame the use of AI systems: intended uses, objectives and rules for responsible use.',
            'annex_a' => ['A.5.10'],
            'modules' => ['riskcatalog'],
        ],
        'A.10' => [
            'theme'   => 'use',
            'fr'      => 'Répartir les responsabilités avec fournisseurs, partenaires et clients tout au long '
                . 'du cycle de vie.',
            'en'      => 'Share responsibilities with suppliers, partners and customers across the life cycle.',
            'annex_a' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'modules' => ['suppliers'],
        ],
    ];

    /**
     * Groupes ISO 42001 reliés à une mesure de l'Annexe A ISO 27001.
     *
     * @return list<string>
     */
    public static function objectivesForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::OBJECTIVES as $code => $objective) {
            if (in_array($annexACode, $objective['annex_a'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

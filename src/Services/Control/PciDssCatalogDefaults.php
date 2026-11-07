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
 * Issue #379 : PCI DSS v4.0.1 (Payment Card Industry Data Security Standard), exigé de toute
 * organisation qui stocke, traite ou transmet des données de cartes de paiement. Douze exigences
 * principales réparties en six objectifs.
 *
 * Droits : document protégé (© PCI Security Standards Council, LLC). Seuls les numéros des douze
 * exigences sont repris ; leur objet est résumé avec les mots de ce projet, jamais le texte du
 * standard, et le regroupement par objectif est reformulé. La correspondance `annex_a` avec
 * ISO/IEC 27001:2022 est indicative et établie par ce projet. Mention affichée sous la carte PCI DSS
 * de templates/referentiels.html.twig.
 */
final class PciDssCatalogDefaults
{
    public const SOURCE_URL = 'https://www.pcisecuritystandards.org/document_library/';

    /**
     * @var array<string, array{fr: string, en: string}> Objectif (formulation de ce projet) => intitulé.
     */
    public const GOALS = [
        'network'       => ['fr' => 'Réseau et systèmes sécurisés', 'en' => 'Secure network and systems'],
        'data'          => ['fr' => 'Protection des données de carte', 'en' => 'Protection of card data'],
        'vulnerability' => ['fr' => 'Gestion des vulnérabilités', 'en' => 'Vulnerability management'],
        'access'        => ['fr' => 'Contrôle d\'accès', 'en' => 'Access control'],
        'monitoring'    => ['fr' => 'Surveillance et tests', 'en' => 'Monitoring and testing'],
        'policy'        => ['fr' => 'Politique et organisation', 'en' => 'Policy and organisation'],
    ];

    /** Modules du plugin qu'une exigence peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'networkflows', 'accounthygiene', 'vulnerabilities', 'accessreviews', 'policies', 'trainings',
        'suppliers', 'risks', 'incidents',
    ];

    /**
     * Exigence => objectif, objet résumé par ce projet (fr, en), mesures de l'Annexe A ISO 27001
     * concernées et modules du plugin qui y répondent. Clés numériques, donc int en PHP.
     *
     * @var array<int|string, array{goal: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const REQUIREMENTS = [
        '1' => [
            'goal'    => 'network',
            'fr'      => 'Filtrer les flux réseau autour de l\'environnement des données de carte et tenir à jour '
                . 'les règles et schémas de flux.',
            'en'      => 'Filter network flows around the card data environment and keep rules and flow diagrams '
                . 'up to date.',
            'annex_a' => ['A.8.20', 'A.8.21', 'A.8.22'],
            'modules' => ['networkflows'],
        ],
        '2' => [
            'goal'    => 'network',
            'fr'      => 'Durcir la configuration de chaque composant : comptes et mots de passe par défaut '
                . 'supprimés, services inutiles désactivés.',
            'en'      => 'Harden the configuration of every component: default accounts and passwords removed, '
                . 'needless services disabled.',
            'annex_a' => ['A.8.9'],
            'modules' => ['accounthygiene'],
        ],
        '3' => [
            'goal'    => 'data',
            'fr'      => 'Limiter les données de carte conservées, ne jamais garder les données sensibles '
                . 'd\'authentification et rendre illisibles celles stockées.',
            'en'      => 'Limit the card data kept, never keep sensitive authentication data and make stored data '
                . 'unreadable.',
            'annex_a' => ['A.5.33', 'A.8.10', 'A.8.11', 'A.8.24'],
            'modules' => [],
        ],
        '4' => [
            'goal'    => 'data',
            'fr'      => 'Chiffrer fortement les données de carte qui transitent sur des réseaux ouverts.',
            'en'      => 'Strongly encrypt card data sent over open networks.',
            'annex_a' => ['A.5.14', 'A.8.24'],
            'modules' => [],
        ],
        '5' => [
            'goal'    => 'vulnerability',
            'fr'      => 'Protéger systèmes et réseaux contre les logiciels malveillants, y compris l\'hameçonnage.',
            'en'      => 'Protect systems and networks against malware, phishing included.',
            'annex_a' => ['A.8.7'],
            'modules' => [],
        ],
        '6' => [
            'goal'    => 'vulnerability',
            'fr'      => 'Développer et maintenir des logiciels sûrs : correctifs à temps, inventaire des '
                . 'composants, développement sécurisé.',
            'en'      => 'Build and maintain secure software: timely patches, component inventory, secure '
                . 'development.',
            'annex_a' => ['A.8.8', 'A.8.25', 'A.8.28', 'A.8.32'],
            'modules' => ['vulnerabilities'],
        ],
        '7' => [
            'goal'    => 'access',
            'fr'      => 'N\'accorder l\'accès aux données de carte qu\'au besoin d\'en connaître, et le revoir '
                . 'régulièrement.',
            'en'      => 'Grant access to card data on a need-to-know basis only, and review it regularly.',
            'annex_a' => ['A.5.15', 'A.5.18', 'A.8.3'],
            'modules' => ['accessreviews'],
        ],
        '8' => [
            'goal'    => 'access',
            'fr'      => 'Identifier chaque utilisateur et authentifier fortement les accès, avec MFA pour '
                . 'l\'environnement des données de carte.',
            'en'      => 'Identify every user and strongly authenticate access, with MFA for the card data '
                . 'environment.',
            'annex_a' => ['A.5.16', 'A.5.17', 'A.8.2', 'A.8.5'],
            'modules' => ['accounthygiene'],
        ],
        '9' => [
            'goal'    => 'access',
            'fr'      => 'Restreindre l\'accès physique aux locaux, supports et terminaux de paiement.',
            'en'      => 'Restrict physical access to premises, media and payment terminals.',
            'annex_a' => ['A.7.1', 'A.7.2', 'A.7.4', 'A.7.10'],
            'modules' => [],
        ],
        '10' => [
            'goal'    => 'monitoring',
            'fr'      => 'Journaliser les accès aux systèmes et aux données de carte, et examiner les journaux.',
            'en'      => 'Log access to systems and card data, and review the logs.',
            'annex_a' => ['A.8.15', 'A.8.16', 'A.8.17'],
            'modules' => [],
        ],
        '11' => [
            'goal'    => 'monitoring',
            'fr'      => 'Tester régulièrement la sécurité : scans de vulnérabilités, tests d\'intrusion, '
                . 'détection des modifications.',
            'en'      => 'Test security regularly: vulnerability scans, penetration tests, change detection.',
            'annex_a' => ['A.8.8', 'A.8.16', 'A.8.29'],
            'modules' => ['vulnerabilities'],
        ],
        '12' => [
            'goal'    => 'policy',
            'fr'      => 'Piloter la sécurité : politique, analyses de risques ciblées, sensibilisation, '
                . 'prestataires et réponse aux incidents.',
            'en'      => 'Steer security: policy, targeted risk analyses, awareness, service providers and '
                . 'incident response.',
            'annex_a' => ['A.5.1', 'A.5.19', 'A.5.24', 'A.6.3'],
            'modules' => ['policies', 'risks', 'trainings', 'suppliers', 'incidents'],
        ],
    ];

    /**
     * Exigences PCI DSS reliées à une mesure de l'Annexe A ISO 27001.
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

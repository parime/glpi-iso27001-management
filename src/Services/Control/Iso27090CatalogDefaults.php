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
 * ISO/IEC 27090:2026, « Cybersecurity — Artificial intelligence (AI) — Addressing security threats
 * and compromises to AI systems » (1re édition, octobre 2026) : lignes directrices, non
 * certifiables, à appliquer avec ISO/IEC 27001 et ISO/IEC 42001.
 *
 * Droits : document protégé (© ISO/IEC 2026, toute reproduction interdite sans licence). Seuls les
 * numéros de section sont repris ; l'objet de chaque section est résumé avec les mots de ce projet,
 * jamais le texte de la norme. La correspondance `annex_a` avec ISO/IEC 27001:2022 est indicative et
 * établie par ce projet (la norme n'en publie pas). Mention affichée sous la carte ISO/IEC 27090 de
 * templates/referentiels.html.twig.
 */
final class Iso27090CatalogDefaults
{
    public const SOURCE_URL = 'https://www.iso.org/standard/27090';

    /**
     * @var array<int, array{fr: string, en: string}> Chapitre => objet (mots de ce projet) ; clés
     * numériques, donc int en PHP.
     */
    public const CHAPTERS = [
        '5' => [
            'fr' => 'Appliquer la sécurité de l\'information aux systèmes d\'IA',
            'en' => 'Applying information security to AI systems',
        ],
        '6' => [
            'fr' => 'Compromissions propres aux systèmes d\'IA',
            'en' => 'Compromises specific to AI systems',
        ],
        '7' => [
            'fr' => 'Mesures transverses et leurs interactions',
            'en' => 'Cross-cutting measures and their interactions',
        ],
    ];

    /**
     * Section => chapitre, objet résumé par ce projet (fr, en) et mesures de l'Annexe A concernées.
     *
     * @var array<string, array{chapter: string, fr: string, en: string, annex_a: list<string>}>
     */
    public const TOPICS = [
        '5.2' => [
            'chapter' => '5',
            'fr'      => 'Gouvernance de l\'IA : savoir quels systèmes d\'IA sont utilisés, par qui, avec quelles '
                . 'données et sous quelle responsabilité.',
            'en'      => 'AI governance: know which AI systems are used, by whom, with which data and under whose '
                . 'responsibility.',
            'annex_a' => ['A.5.1', 'A.5.2', 'A.5.9', 'A.5.10'],
        ],
        '5.3' => [
            'chapter' => '5',
            'fr'      => 'Confiance zéro : un système d\'IA, ses données et ses outils ne reçoivent que les accès '
                . 'strictement nécessaires.',
            'en'      => 'Zero trust: an AI system, its data and its tools only get the access they strictly need.',
            'annex_a' => ['A.5.15', 'A.5.18', 'A.8.2', 'A.8.3'],
        ],
        '5.4' => [
            'chapter' => '5',
            'fr'      => 'Bonnes pratiques de développement logiciel appliquées aux projets d\'IA.',
            'en'      => 'Software engineering good practices applied to AI projects.',
            'annex_a' => ['A.8.25', 'A.8.28', 'A.8.29', 'A.8.32'],
        ],
        '5.5' => [
            'chapter' => '5',
            'fr'      => 'Chaîne d\'approvisionnement : modèles, jeux de données, bibliothèques et services d\'IA '
                . 'fournis par des tiers.',
            'en'      => 'Supply chain: models, datasets, libraries and AI services supplied by third parties.',
            'annex_a' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.23'],
        ],
        '5.6' => [
            'chapter' => '5',
            'fr'      => 'Modélisation des menaces propres à chaque système d\'IA.',
            'en'      => 'Threat modelling specific to each AI system.',
            'annex_a' => ['A.5.7', 'A.8.27'],
        ],
        '5.7' => [
            'chapter' => '5',
            'fr'      => 'Tests offensifs (red teaming) des systèmes d\'IA.',
            'en'      => 'Offensive testing (red teaming) of AI systems.',
            'annex_a' => ['A.8.8', 'A.8.29'],
        ],
        '6.2' => [
            'chapter' => '6',
            'fr'      => 'Empoisonnement des données : des données d\'entraînement altérées faussent le '
                . 'comportement du modèle.',
            'en'      => 'Data poisoning: tampered training data skews the model\'s behaviour.',
            'annex_a' => ['A.5.21', 'A.8.3', 'A.8.32'],
        ],
        '6.3' => [
            'chapter' => '6',
            'fr'      => 'Évasion : des entrées fabriquées trompent le modèle au moment de l\'utilisation.',
            'en'      => 'Evasion: crafted inputs mislead the model at the time of use.',
            'annex_a' => ['A.8.16', 'A.8.26', 'A.8.29'],
        ],
        '6.4' => [
            'chapter' => '6',
            'fr'      => 'Inférence d\'appartenance : déduire qu\'une donnée, par exemple personnelle, a servi à '
                . 'l\'entraînement.',
            'en'      => 'Membership inference: deducing that a piece of data, for example personal data, was used '
                . 'for training.',
            'annex_a' => ['A.5.34', 'A.8.11', 'A.8.12'],
        ],
        '6.5' => [
            'chapter' => '6',
            'fr'      => 'Exfiltration du modèle : vol ou reconstitution d\'un modèle et de son savoir-faire.',
            'en'      => 'Model exfiltration: theft or reconstruction of a model and its know-how.',
            'annex_a' => ['A.5.32', 'A.8.3', 'A.8.12', 'A.8.16'],
        ],
        '6.6' => [
            'chapter' => '6',
            'fr'      => 'Inversion du modèle : retrouver des données d\'entraînement à partir des réponses.',
            'en'      => 'Model inversion: recovering training data from the model\'s answers.',
            'annex_a' => ['A.5.34', 'A.8.11', 'A.8.12'],
        ],
        '6.7' => [
            'chapter' => '6',
            'fr'      => 'Empoisonnement direct : modification des paramètres du modèle lui-même.',
            'en'      => 'Direct model poisoning: tampering with the parameters of the model itself.',
            'annex_a' => ['A.8.3', 'A.8.4', 'A.8.32'],
        ],
        '6.8' => [
            'chapter' => '6',
            'fr'      => 'Sorties sensibles : le modèle révèle des informations confidentielles ou personnelles.',
            'en'      => 'Sensitive output: the model discloses confidential or personal information.',
            'annex_a' => ['A.5.12', 'A.5.34', 'A.8.12'],
        ],
        '6.9' => [
            'chapter' => '6',
            'fr'      => 'Injection de prompt : des instructions cachées dans une entrée détournent le système '
                . 'd\'IA de sa tâche.',
            'en'      => 'Prompt injection: instructions hidden in an input divert the AI system from its task.',
            'annex_a' => ['A.8.16', 'A.8.26', 'A.8.28'],
        ],
        '6.10' => [
            'chapter' => '6',
            'fr'      => 'Sortie piégée : la réponse du modèle contient un contenu malveillant réutilisé par une '
                . 'autre application (code, commande, lien).',
            'en'      => 'Booby-trapped output: the model\'s answer carries malicious content reused by another '
                . 'application (code, command, link).',
            'annex_a' => ['A.8.26', 'A.8.28'],
        ],
        '6.11' => [
            'chapter' => '6',
            'fr'      => 'Limiter les données confiées au système, encadrer son comportement en exploitation et '
                . 'tracer la provenance des données et des modèles.',
            'en'      => 'Limit the data given to the system, keep its behaviour in check in operation and trace '
                . 'the provenance of data and models.',
            'annex_a' => ['A.5.12', 'A.5.21', 'A.8.10', 'A.8.16'],
        ],
        '7.3' => [
            'chapter' => '7',
            'fr'      => 'Maintenir les mesures tout au long du cycle de vie du système d\'IA.',
            'en'      => 'Keep the measures in place across the whole AI system life cycle.',
            'annex_a' => ['A.5.8', 'A.8.25'],
        ],
        '7.4' => [
            'chapter' => '7',
            'fr'      => 'Revoir régulièrement le modèle et les mesures, qui perdent en efficacité avec le temps.',
            'en'      => 'Regularly review the model and the measures, which lose effectiveness over time.',
            'annex_a' => ['A.5.35', 'A.5.36'],
        ],
        '7.5' => [
            'chapter' => '7',
            'fr'      => 'Journaliser et surveiller l\'usage du système d\'IA.',
            'en'      => 'Log and monitor the use of the AI system.',
            'annex_a' => ['A.8.15', 'A.8.16'],
        ],
        '7.6' => [
            'chapter' => '7',
            'fr'      => 'Protéger l\'environnement de développement et d\'entraînement.',
            'en'      => 'Protect the development and training environment.',
            'annex_a' => ['A.8.4', 'A.8.25', 'A.8.31'],
        ],
        '7.7' => [
            'chapter' => '7',
            'fr'      => 'Détecter et filtrer les entrées malveillantes ou hors du domaine prévu.',
            'en'      => 'Detect and filter malicious inputs or inputs outside the intended domain.',
            'annex_a' => ['A.8.16', 'A.8.26'],
        ],
        '7.8' => [
            'chapter' => '7',
            'fr'      => 'Limiter le volume d\'utilisation du modèle pour freiner les attaques par requêtes '
                . 'répétées.',
            'en'      => 'Limit how much the model can be used to slow down attacks based on repeated queries.',
            'annex_a' => ['A.8.6', 'A.8.16'],
        ],
    ];

    /**
     * Correspondance inverse : mesure de l'Annexe A => sections ISO/IEC 27090 qui y renvoient.
     *
     * @return list<string>
     */
    public static function topicsForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::TOPICS as $code => $topic) {
            if (in_array($annexACode, $topic['annex_a'], true)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}

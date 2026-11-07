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
 * Issue #379 : ISO/IEC 27701:2025, système de management de la protection de la vie privée (PIMS).
 * Depuis l'édition 2025 la norme est autonome ; son Annexe A (normative) range ses mesures en trois
 * tables selon le rôle : A.1 responsables de traitement, A.2 sous-traitants, A.3 mesures de sécurité
 * communes aux deux.
 *
 * Droits : document protégé (© ISO/IEC 2025, reproduction interdite sans licence). Seuls les numéros
 * des tables et des objectifs (A.x.2 à A.x.5) sont repris, pas les mesures une à une ; leur objet est
 * résumé avec les mots de ce projet, jamais le texte de la norme. La correspondance `annex_a` avec
 * ISO/IEC 27001:2022 est indicative et établie par ce projet. Mention affichée sous la carte
 * ISO/IEC 27701 de templates/referentiels.html.twig.
 */
final class Iso27701CatalogDefaults
{
    public const SOURCE_URL = 'https://www.iso.org/standard/27701';

    /**
     * @var array<string, array{fr: string, en: string}> Table de l'Annexe A => rôle visé (mots de ce projet).
     */
    public const TABLES = [
        'A.1' => ['fr' => 'Responsable de traitement', 'en' => 'PII controller'],
        'A.2' => ['fr' => 'Sous-traitant', 'en' => 'PII processor'],
        'A.3' => ['fr' => 'Sécurité commune aux deux rôles', 'en' => 'Security shared by both roles'],
    ];

    /** Modules du plugin qu'un objectif peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = ['processing', 'dpia', 'requests', 'incidents', 'suppliers'];

    /**
     * Objectif => table, objet résumé par ce projet (fr, en), mesures de l'Annexe A ISO 27001
     * concernées et modules du plugin qui y répondent.
     *
     * @var array<string, array{table: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const OBJECTIVES = [
        'A.1.2' => [
            'table'   => 'A.1',
            'fr'      => 'Encadrer chaque traitement : finalités et base légale documentées, consentement quand '
                . 'il est requis, analyse d\'impact, contrats avec les sous-traitants et registre des '
                . 'traitements.',
            'en'      => 'Frame each processing: documented purposes and legal basis, consent where required, '
                . 'impact assessment, contracts with processors and record of processing.',
            'annex_a' => ['A.5.9', 'A.5.20', 'A.5.31', 'A.5.34'],
            'modules' => ['processing', 'dpia'],
        ],
        'A.1.3' => [
            'table'   => 'A.1',
            'fr'      => 'Respecter les droits des personnes : les informer, traiter leurs demandes (accès, '
                . 'rectification, effacement, opposition) et encadrer les décisions automatisées.',
            'en'      => 'Respect the rights of individuals: inform them, handle their requests (access, '
                . 'rectification, erasure, objection) and frame automated decisions.',
            'annex_a' => ['A.5.31', 'A.5.34'],
            'modules' => ['requests'],
        ],
        'A.1.4' => [
            'table'   => 'A.1',
            'fr'      => 'Protéger la vie privée dès la conception et par défaut : minimiser les données, les '
                . 'garder exactes, limiter leur conservation et les supprimer en fin de traitement.',
            'en'      => 'Privacy by design and by default: minimise data, keep it accurate, limit its retention '
                . 'and delete it when the processing ends.',
            'annex_a' => ['A.5.34', 'A.8.10', 'A.8.11', 'A.8.25'],
            'modules' => ['processing', 'dpia'],
        ],
        'A.1.5' => [
            'table'   => 'A.1',
            'fr'      => 'Maîtriser les partages et transferts : base des transferts hors de la juridiction, '
                . 'pays destinataires connus et trace des communications à des tiers.',
            'en'      => 'Control sharing and transfers: basis for transfers outside the jurisdiction, known '
                . 'destination countries and a trace of disclosures to third parties.',
            'annex_a' => ['A.5.14', 'A.5.19', 'A.5.34'],
            'modules' => ['processing', 'suppliers'],
        ],
        'A.2.2' => [
            'table'   => 'A.2',
            'fr'      => 'Traiter uniquement sur instruction du client, dans le cadre du contrat, alerter si une '
                . 'instruction semble illicite et tenir la trace des traitements réalisés pour lui.',
            'en'      => 'Process only on the customer\'s instructions, within the contract, warn when an '
                . 'instruction seems unlawful and keep a trace of the processing done for them.',
            'annex_a' => ['A.5.20', 'A.5.31', 'A.5.34'],
            'modules' => ['processing'],
        ],
        'A.2.3' => [
            'table'   => 'A.2',
            'fr'      => 'Aider le client à répondre aux demandes des personnes concernées.',
            'en'      => 'Help the customer answer the requests of data subjects.',
            'annex_a' => ['A.5.34'],
            'modules' => ['requests'],
        ],
        'A.2.4' => [
            'table'   => 'A.2',
            'fr'      => 'Limiter les fichiers temporaires, restituer ou supprimer les données en fin de '
                . 'contrat et protéger leurs transmissions.',
            'en'      => 'Limit temporary files, return or delete the data at the end of the contract and '
                . 'protect its transmission.',
            'annex_a' => ['A.5.14', 'A.8.10', 'A.5.34'],
            'modules' => [],
        ],
        'A.2.5' => [
            'table'   => 'A.2',
            'fr'      => 'Encadrer transferts et sous-traitance ultérieure : pays connus, demandes de '
                . 'communication signalées au client, sous-traitants ultérieurs déclarés et changements '
                . 'annoncés.',
            'en'      => 'Frame transfers and sub-processing: known countries, disclosure requests reported to '
                . 'the customer, sub-processors declared and changes announced.',
            'annex_a' => ['A.5.14', 'A.5.19', 'A.5.20', 'A.5.21'],
            'modules' => ['suppliers'],
        ],
        'A.3' => [
            'table'   => 'A.3',
            'fr'      => 'Mesures de sécurité adaptées aux données personnelles : rôles, sensibilisation, '
                . 'classification, gestion des violations, journalisation, chiffrement, sauvegarde et '
                . 'effacement.',
            'en'      => 'Security controls adapted to personal data: roles, awareness, classification, breach '
                . 'management, logging, encryption, backup and deletion.',
            'annex_a' => ['A.5.2', 'A.5.12', 'A.5.24', 'A.5.26', 'A.6.3', 'A.8.10', 'A.8.13', 'A.8.15',
                'A.8.24'],
            'modules' => ['incidents'],
        ],
    ];

    /**
     * Objectifs ISO 27701 reliés à une mesure de l'Annexe A ISO 27001.
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

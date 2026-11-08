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
 * Issue #379 : référentiel de certification Hébergeur de données de santé (HDS), exigences v2.0,
 * approuvé par l'arrêté du 26 avril 2024 (Agence du Numérique en Santé, document public). Il
 * s'ajoute à un SMSI certifié ISO 27001 : exigences 01 à 16 sur le SMSI (chapitres 4 et 5), 17 à
 * 27 sur le contrat avec le client (article R.1111-11 du code de la santé publique), 28 à 31 sur la
 * souveraineté des données (article R.1111-9-1).
 *
 * Le projet de version 2.1 (en concertation) détaille l'exigence 23 en 23-1, 23-2 et 23-3 (accès
 * aux données et réglementations extra-européennes dans le contrat) : signalé sur l'exigence 23.
 *
 * Les exigences sont résumées par ce projet avec leur numéro officiel ; seul le référentiel publié
 * par l'ANS fait foi. Correspondance `annex_a` indicative, établie par ce projet.
 */
final class HdsCatalogDefaults
{
    public const SOURCE_URL = 'https://esante.gouv.fr/offres-services/hds/certification-hebergeur-de-donnees-de-sante';

    /**
     * @var array<string, array{fr: string, en: string}> Chapitre du référentiel => intitulé.
     */
    public const CHAPTERS = [
        'smsi'        => [
            'fr' => 'Certification et SMSI (chapitres 4 et 5)',
            'en' => 'Certification and ISMS (chapters 4 and 5)',
        ],
        'contract'    => [
            'fr' => 'Contrat avec le client (chapitre 6)',
            'en' => 'Contract with the customer (chapter 6)',
        ],
        'sovereignty' => [
            'fr' => 'Souveraineté des données (chapitre 7)',
            'en' => 'Data sovereignty (chapter 7)',
        ],
    ];

    /** Modules du plugin qu'une exigence peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'audits', 'risks', 'riskcatalog', 'suppliers', 'trainings', 'incidents', 'requests', 'processing', 'policies',
    ];

    /**
     * Exigence (numéro officiel) => chapitre, exigence résumée par ce projet (fr, en), mesures de
     * l'Annexe A ISO 27001 concernées et modules du plugin. Clés numériques, donc int en PHP.
     *
     * @var array<int|string, array{chapter: string, fr: string, en: string, annex_a: list<string>,
     *     modules: list<string>}>
     */
    public const REQUIREMENTS = [
        '1'  => [
            'chapter' => 'smsi',
            'fr'      => 'Conditions de certification : SMSI certifié ISO 27001 couvrant toutes les activités '
                . 'd\'hébergement, contrats conformes, souveraineté respectée, garanties présentées aux clients.',
            'en'      => 'Certification conditions: ISO 27001 certified ISMS covering all hosting activities, '
                . 'compliant contracts, sovereignty respected, guarantees presented to customers.',
            'annex_a' => ['A.5.31', 'A.5.36'],
            'modules' => ['audits'],
        ],
        '2'  => [
            'chapter' => 'smsi',
            'fr'      => 'Compter la protection des données de santé confiées par les clients parmi les enjeux '
                . 'de l\'organisation.',
            'en'      => 'Count the protection of the health data entrusted by customers among the organisation\'s '
                . 'issues.',
            'annex_a' => ['A.5.34'],
            'modules' => [],
        ],
        '3'  => [
            'chapter' => 'smsi',
            'fr'      => 'Prendre en compte le cadre juridique de la protection des données de santé dans les '
                . 'exigences des parties intéressées.',
            'en'      => 'Take the legal framework of health data protection into account in the interested '
                . 'parties\' requirements.',
            'annex_a' => ['A.5.31', 'A.5.34'],
            'modules' => [],
        ],
        '4'  => [
            'chapter' => 'smsi',
            'fr'      => 'Périmètre du SMSI : tous les traitements de données de santé, sauvegardes et '
                . 'transferts de supports compris.',
            'en'      => 'ISMS scope: all health data processing, backups and transfers of media included.',
            'annex_a' => ['A.5.9', 'A.8.13', 'A.7.10'],
            'modules' => ['processing'],
        ],
        '5'  => [
            'chapter' => 'smsi',
            'fr'      => 'Apprécier au moins les risques imposés : supports matériels, perte de contrôle des '
                . 'supports, flux, droits d\'accès, interventions, usages imprévus, défaillances, lois '
                . 'extra-européennes.',
            'en'      => 'Assess at least the mandated risks: media, loss of control of media, flows, access '
                . 'rights, interventions, unexpected uses, failures, non-European laws.',
            'annex_a' => ['A.7.5', 'A.7.10', 'A.5.14', 'A.5.18', 'A.8.15', 'A.8.14'],
            'modules' => ['risks', 'riskcatalog'],
        ],
        '6'  => [
            'chapter' => 'smsi',
            'fr'      => 'Maîtriser les changements des mesures de sécurité des sous-traitants.',
            'en'      => 'Control the changes to the subcontractors\' security measures.',
            'annex_a' => ['A.5.22'],
            'modules' => ['suppliers'],
        ],
        '7'  => [
            'chapter' => 'smsi',
            'fr'      => 'Interfaces proposées aux clients et support de premier niveau au moins en français.',
            'en'      => 'Customer interfaces and first-level support available at least in French.',
            'annex_a' => ['A.5.37'],
            'modules' => [],
        ],
        '8'  => [
            'chapter' => 'smsi',
            'fr'      => 'Déclaration d\'applicabilité disponible en français pour les auditeurs.',
            'en'      => 'Statement of Applicability available in French for the auditors.',
            'annex_a' => ['A.5.36'],
            'modules' => [],
        ],
        '9'  => [
            'chapter' => 'smsi',
            'fr'      => 'Objectifs de sécurité intégrant la protection des données de santé et le respect du '
                . 'RGPD.',
            'en'      => 'Security objectives including health data protection and GDPR compliance.',
            'annex_a' => ['A.5.34', 'A.5.31'],
            'modules' => [],
        ],
        '10' => [
            'chapter' => 'smsi',
            'fr'      => 'Sensibiliser le personnel, et celui des sous-traitants, à la criticité des données de '
                . 'santé.',
            'en'      => 'Make staff, and subcontractors\' staff, aware of how critical health data is.',
            'annex_a' => ['A.6.3'],
            'modules' => ['trainings'],
        ],
        '11' => [
            'chapter' => 'smsi',
            'fr'      => 'Tenir un point de contact par client, capable de désigner un professionnel de santé, '
                . 'et transmettre la liste à l\'autorité sur demande.',
            'en'      => 'Keep a contact point per customer, able to name a health professional, and give the '
                . 'list to the authority on request.',
            'annex_a' => ['A.5.5', 'A.5.20'],
            'modules' => [],
        ],
        '12' => [
            'chapter' => 'smsi',
            'fr'      => 'Remettre aux clients le certificat HDS et ceux des sous-traitants certifiés.',
            'en'      => 'Give customers the HDS certificate and those of certified subcontractors.',
            'annex_a' => ['A.5.20'],
            'modules' => ['suppliers'],
        ],
        '13' => [
            'chapter' => 'smsi',
            'fr'      => 'Planifier et contrôler le partage des responsabilités de sécurité avec le client.',
            'en'      => 'Plan and check how security responsibilities are shared with the customer.',
            'annex_a' => ['A.5.2', 'A.5.23'],
            'modules' => [],
        ],
        '14' => [
            'chapter' => 'smsi',
            'fr'      => 'Prévoir une procédure en cas de perte ou de suspension de la certification d\'un '
                . 'sous-traitant.',
            'en'      => 'Have a procedure for when a subcontractor loses or has its certification suspended.',
            'annex_a' => ['A.5.19', 'A.5.22'],
            'modules' => ['suppliers'],
        ],
        '15' => [
            'chapter' => 'smsi',
            'fr'      => 'Permettre au client de vérifier : audits de ses ressources dédiées, synthèse d\'audit '
                . 'de moins de trois ans, traces d\'accès, dernier rapport d\'audit HDS.',
            'en'      => 'Let the customer check: audits of its dedicated resources, audit summary under three '
                . 'years old, access traces, latest HDS audit report.',
            'annex_a' => ['A.5.35', 'A.8.15'],
            'modules' => ['audits'],
        ],
        '16' => [
            'chapter' => 'smsi',
            'fr'      => 'Audits internes : conformité au référentiel et revue des traces d\'accès aux données '
                . 'de santé.',
            'en'      => 'Internal audits: compliance with the framework and review of the access traces to '
                . 'health data.',
            'annex_a' => ['A.5.35', 'A.8.15', 'A.8.16'],
            'modules' => ['audits'],
        ],
        '17' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : périmètre du certificat HDS et dates de délivrance et de renouvellement.',
            'en'      => 'Clause: scope of the HDS certificate, issue and renewal dates.',
            'annex_a' => ['A.5.20'],
            'modules' => ['suppliers'],
        ],
        '18' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : description des prestations et résultats attendus (disponibilité, intégrité, '
                . 'confidentialité, auditabilité).',
            'en'      => 'Clause: description of the services and expected results (availability, integrity, '
                . 'confidentiality, auditability).',
            'annex_a' => ['A.5.20'],
            'modules' => ['suppliers'],
        ],
        '19' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : exercice des droits des personnes, signalement des violations au responsable '
                . 'de traitement, audits du DPO.',
            'en'      => 'Clause: exercise of data subjects\' rights, breach reporting to the controller, DPO '
                . 'audits.',
            'annex_a' => ['A.5.34', 'A.5.24'],
            'modules' => ['requests', 'incidents'],
        ],
        '20' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : référent du client à contacter pour les incidents touchant les données de '
                . 'santé.',
            'en'      => 'Clause: customer contact for incidents affecting the health data.',
            'annex_a' => ['A.5.20', 'A.5.24'],
            'modules' => ['incidents'],
        ],
        '21' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : indicateurs de qualité et de performance, niveau garanti, périodicité, '
                . 'pénalités éventuelles.',
            'en'      => 'Clause: quality and performance indicators, guaranteed level, frequency, any penalties.',
            'annex_a' => ['A.5.20', 'A.5.22'],
            'modules' => ['suppliers'],
        ],
        '22' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : conditions du recours à des prestataires externes, à niveau de protection '
                . 'équivalent (article 28.4 du RGPD).',
            'en'      => 'Clause: conditions for using external providers, at an equivalent level of protection '
                . '(GDPR article 28.4).',
            'annex_a' => ['A.5.19', 'A.5.20'],
            'modules' => ['suppliers'],
        ],
        '23' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : encadrement des accès aux données hébergées (le projet v2.1 y ajoute les '
                . 'transferts, accès à distance compris, et les lois extra-européennes : 23-1 à 23-3).',
            'en'      => 'Clause: control of access to the hosted data (draft v2.1 adds transfers, remote access '
                . 'included, and non-European laws: 23-1 to 23-3).',
            'annex_a' => ['A.5.15', 'A.5.14'],
            'modules' => [],
        ],
        '24' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : obligations en cas d\'évolution technique, accord préalable du client si '
                . 'niveaux de service ou garanties baissent.',
            'en'      => 'Clause: obligations in case of technical change, prior customer consent if service '
                . 'levels or guarantees go down.',
            'annex_a' => ['A.8.32', 'A.5.20'],
            'modules' => [],
        ],
        '25' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : garanties et procédures couvrant une défaillance de l\'hébergeur.',
            'en'      => 'Clause: guarantees and procedures covering a failure of the host.',
            'annex_a' => ['A.5.20', 'A.5.30'],
            'modules' => [],
        ],
        '26' => [
            'chapter' => 'contract',
            'fr'      => 'Clause : interdiction d\'utiliser les données hébergées à d\'autres fins que '
                . 'l\'hébergement.',
            'en'      => 'Clause: prohibition of using the hosted data for any purpose other than hosting.',
            'annex_a' => ['A.5.34', 'A.5.10'],
            'modules' => [],
        ],
        '27' => [
            'chapter' => 'contract',
            'fr'      => 'Clause de réversibilité : restitution complète, destruction des copies, coûts et '
                . 'délais, formats exploitables.',
            'en'      => 'Reversibility clause: full return, destruction of copies, costs and delays, usable '
                . 'formats.',
            'annex_a' => ['A.8.10', 'A.5.14', 'A.5.20'],
            'modules' => [],
        ],
        '28' => [
            'chapter' => 'sovereignty',
            'fr'      => 'Stocker les données de santé exclusivement dans l\'Espace économique européen, et '
                . 'indiquer au client où.',
            'en'      => 'Store health data only within the European Economic Area, and tell the customer where.',
            'annex_a' => ['A.5.34', 'A.5.23', 'A.5.31'],
            'modules' => ['processing'],
        ],
        '29' => [
            'chapter' => 'sovereignty',
            'fr'      => 'Tout transfert hors EEE, accès à distance compris, repose sur une décision '
                . 'd\'adéquation ou des garanties de l\'article 46 du RGPD, portées à la connaissance du client.',
            'en'      => 'Any transfer outside the EEA, remote access included, relies on an adequacy decision or '
                . 'GDPR article 46 safeguards, made known to the customer.',
            'annex_a' => ['A.5.14', 'A.5.34'],
            'modules' => ['processing', 'suppliers'],
        ],
        '30' => [
            'chapter' => 'sovereignty',
            'fr'      => 'Soumis à une loi d\'un pays tiers non adéquat : lister ces lois, les mesures '
                . 'd\'atténuation et les risques résiduels dans le contrat.',
            'en'      => 'Subject to a law of a non-adequate third country: list these laws, the mitigation '
                . 'measures and the residual risks in the contract.',
            'annex_a' => ['A.5.31', 'A.5.34', 'A.5.23'],
            'modules' => ['suppliers', 'risks'],
        ],
        '31' => [
            'chapter' => 'sovereignty',
            'fr'      => 'Publier et tenir à jour une page de cartographie des transferts hors EEE et des '
                . 'risques d\'accès, communiquée à l\'organisme certificateur.',
            'en'      => 'Publish and keep up to date a page mapping transfers outside the EEA and access risks, '
                . 'given to the certification body.',
            'annex_a' => ['A.5.34', 'A.5.14'],
            'modules' => ['policies'],
        ],
    ];

    /** Numéro affiché comme dans le référentiel : « EXI 05 ». */
    public static function label(int|string $code): string
    {
        return sprintf('EXI %02d', (int) $code);
    }

    /**
     * Exigences HDS reliées à une mesure de l'Annexe A ISO 27001, au format « EXI 05 ».
     *
     * @return list<string>
     */
    public static function requirementsForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::REQUIREMENTS as $code => $requirement) {
            if (in_array($annexACode, $requirement['annex_a'], true)) {
                $codes[] = self::label($code);
            }
        }

        return $codes;
    }
}

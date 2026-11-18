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

namespace GlpiPlugin\Grcmanager\Services\Acn;

use GlpiPlugin\Grcmanager\Services\Control\CatalogLanguage;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;

/**
 * Issue #703 : mesures di sicurezza di base NIS2 italiennes, déterminazione ACN n. 379907/2025
 * (applicable depuis le 15 janvier 2026, remplace la détermination n. 164179 du 14 avril 2025),
 * prise en application du D.Lgs. 138/2024 (transposition italienne de NIS2).
 *
 * - Allegato 1 : soggetti importanti, 37 mesures et 87 exigences (punti).
 * - Allegato 2 : soggetti essenziali, 43 mesures et 116 exigences. L'allegato 2 reprend chaque
 *   point de l'allegato 1 sous le même numéro et en ajoute : `essential` = true marque ces ajouts.
 *
 * Codes : sous-catégories du Framework nazionale per la Cybersecurity e la Data Protection, aligné
 * sur le NIST CSF 2.0 (même code, texte NIST dans NistCsfCatalogDefaults). Relevé sur les PDF
 * publiés par l'ACN (SOURCE_ANNEX_1, SOURCE_ANNEX_2), version « v2 » mise en ligne avec la
 * détermination.
 *
 * Seuls le code, le numéro du point et la référence sont repris de la source. Les intitulés courts et
 * les résumés (`it`, `en`, `fr`) sont écrits par ce projet : ils ne remplacent pas le texte officiel,
 * à lire sur le site de l'ACN. Langue affichée : CatalogLanguage (langue de l'utilisateur, sinon
 * anglais, sinon français).
 *
 * `approval` : le point exige l'approbation des organi di amministrazione e direttivi.
 * `nis2` : renvoi indicatif, établi par ce projet, vers les exigences de la directive (UE) 2022/2555
 * (clés de Nis2CatalogDefaults::REQUIREMENTS). Correspondance avec l'Annexe A ISO 27001 : celle que
 * le NIST publie pour la sous-catégorie (ControlCrosswalkDefaults), donc officielle mais indirecte.
 */
final class AcnCatalogDefaults
{
    public const DETERMINATION = '379907/2025';
    public const LAW = 'D.Lgs. 138/2024';
    public const SOURCE_ANNEX_1 = 'https://www.acn.gov.it/portale/documents/d/guest/allegato-1-v2';
    public const SOURCE_ANNEX_2 = 'https://www.acn.gov.it/portale/documents/d/guest/allegato-2-v2';

    public const CATEGORY_IMPORTANT = 'important';
    public const CATEGORY_ESSENTIAL = 'essential';
    public const CATEGORIES_ENTITY = [self::CATEGORY_IMPORTANT, self::CATEGORY_ESSENTIAL];

    /** @var array<string, array{it: string, en: string, fr: string}> Fonction du Framework nazionale. */
    public const FUNCTIONS = [
        'GV' => ['it' => 'Governo (GOVERN)', 'en' => 'Govern', 'fr' => 'Gouverner'],
        'ID' => ['it' => 'Identificazione (IDENTIFY)', 'en' => 'Identify', 'fr' => 'Identifier'],
        'PR' => ['it' => 'Protezione (PROTECT)', 'en' => 'Protect', 'fr' => 'Protéger'],
        'DE' => ['it' => 'Rilevamento (DETECT)', 'en' => 'Detect', 'fr' => 'Détecter'],
        'RS' => ['it' => 'Risposta (RESPOND)', 'en' => 'Respond', 'fr' => 'Répondre'],
        'RC' => ['it' => 'Ripristino (RECOVER)', 'en' => 'Recover', 'fr' => 'Rétablir'],
    ];

    /**
     * Mesures, dans l'ordre des allegati. Les points sont numérotés comme dans la source.
     *
     * @var array<string, array{it: string, en: string, fr: string, nis2: list<string>,
     *     points: array<int, array{it: string, en: string, fr: string, essential?: bool, approval?: bool}>}>
     */
    public const MEASURES = [
        'GV.OC-04' => [
            'it' => 'Servizi critici e sistemi rilevanti',
            'en' => 'Critical services and relevant systems',
            'fr' => 'Services critiques et systèmes pertinents',
            'nis2' => ['21.2.a', '21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Tenere aggiornato l\'elenco dei sistemi informativi e di rete rilevanti.',
                    'en' => 'Keep an up-to-date list of the relevant network and information systems.',
                    'fr' => 'Tenir à jour la liste des systèmes d\'information et réseaux pertinents.',
                ],
            ],
        ],
        'GV.RM-03' => [
            'it' => 'Piano di gestione dei rischi',
            'en' => 'Risk management plan',
            'fr' => 'Plan de gestion des risques',
            'nis2' => ['20.1', '21.2.a'],
            'points' => [
                1 => [
                    'it' => 'Definire, attuare, aggiornare e documentare un piano di gestione dei rischi di sicurezza '
                        . 'informatica, coerente con le politiche GV.PO-01.',
                    'en' => 'Define, implement, update and document a cybersecurity risk management plan, in line '
                        . 'with the GV.PO-01 policies.',
                    'fr' => 'Définir, appliquer, tenir à jour et documenter un plan de gestion des risques de '
                        . 'cybersécurité, cohérent avec les politiques GV.PO-01.',
                ],
            ],
        ],
        'GV.RR-02' => [
            'it' => 'Ruoli e responsabilità di sicurezza',
            'en' => 'Security roles and responsibilities',
            'fr' => 'Rôles et responsabilités de sécurité',
            'nis2' => ['20.1', '21.2.a'],
            'points' => [
                1 => [
                    'it' => 'Organizzazione per la sicurezza informatica definita, con ruoli e responsabilità, '
                        . 'approvata dai vertici e comunicata.',
                    'en' => 'Cybersecurity organisation defined with its roles and responsibilities, approved by the '
                        . 'management bodies and communicated.',
                    'fr' => 'Organisation de la cybersécurité définie avec ses rôles et responsabilités, approuvée '
                        . 'par les organes de direction et communiquée.',
                    'approval' => true,
                ],
                2 => [
                    'it' => 'Elenco aggiornato delle persone con ruoli di sicurezza, reso noto alle strutture '
                        . 'interessate.',
                    'en' => 'Up-to-date list of the people holding security roles, shared with the units concerned.',
                    'fr' => 'Liste à jour des personnes ayant un rôle de sécurité, communiquée aux services '
                        . 'concernés.',
                ],
                3 => [
                    'it' => 'L\'organizzazione comprende il punto di contatto, il referente CSIRT e i rispettivi '
                        . 'sostituti.',
                    'en' => 'The organisation includes the point of contact, the CSIRT contact and their deputies.',
                    'fr' => 'L\'organisation comprend le point de contact, le référent CSIRT et leurs suppléants.',
                ],
                4 => [
                    'it' => 'Riesame dei ruoli almeno ogni due anni e dopo incidenti significativi o cambiamenti.',
                    'en' => 'Review of the roles at least every two years and after significant incidents or changes.',
                    'fr' => 'Revue des rôles au moins tous les deux ans et après un incident important ou un '
                        . 'changement.',
                ],
            ],
        ],
        'GV.RR-04' => [
            'it' => 'Sicurezza nelle risorse umane',
            'en' => 'Security in human resources',
            'fr' => 'Sécurité liée aux ressources humaines',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Personale con accesso ai sistemi rilevanti scelto valutando esperienza, capacità e '
                        . 'affidabilità.',
                    'en' => 'Staff with access to the relevant systems chosen after assessing experience, skills and '
                        . 'reliability.',
                    'fr' => 'Personnel ayant accès aux systèmes pertinents choisi après évaluation de son '
                        . 'expérience, de ses compétences et de sa fiabilité.',
                ],
                2 => [
                    'it' => 'Amministratori di sistema scelti con la stessa valutazione.',
                    'en' => 'System administrators chosen after the same assessment.',
                    'fr' => 'Administrateurs système choisis après la même évaluation.',
                ],
                3 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
                4 => [
                    'it' => 'Obblighi di sicurezza che restano validi dopo la fine o il cambio del rapporto di lavoro '
                        . '(es. riservatezza), previsti nel contratto.',
                    'en' => 'Security duties that remain after employment ends or changes (e.g. confidentiality), '
                        . 'set in the contract.',
                    'fr' => 'Obligations de sécurité maintenues après la fin ou le changement du contrat de travail '
                        . '(confidentialité par exemple), prévues au contrat.',
                    'essential' => true,
                ],
                5 => [
                    'it' => 'Procedure documentate per il punto 4.',
                    'en' => 'Documented procedures for point 4.',
                    'fr' => 'Procédures documentées pour le point 4.',
                    'essential' => true,
                ],
            ],
        ],
        'GV.PO-01' => [
            'it' => 'Politiche di sicurezza',
            'en' => 'Security policies',
            'fr' => 'Politiques de sécurité',
            'nis2' => ['20.1', '21.2.a'],
            'points' => [
                1 => [
                    'it' => 'Politiche di sicurezza adottate e documentate almeno per i 16 ambiti elencati (dal '
                        . 'rischio alla risposta agli incidenti).',
                    'en' => 'Security policies adopted and documented for at least the 16 listed areas (from risk '
                        . 'to incident response).',
                    'fr' => 'Politiques de sécurité adoptées et documentées au moins pour les 16 domaines listés '
                        . '(du risque à la réponse aux incidents).',
                ],
                2 => [
                    'it' => 'Le politiche coprono almeno i requisiti della tabella 1 in appendice all\'allegato.',
                    'en' => 'The policies cover at least the requirements of table 1 in the appendix of the annex.',
                    'fr' => 'Les politiques couvrent au moins les exigences du tableau 1 en appendice de l\'annexe.',
                ],
                3 => [
                    'it' => 'Politiche approvate dai vertici e comunicate secondo la necessità di conoscere.',
                    'en' => 'Policies approved by the management bodies and shared on a need-to-know basis.',
                    'fr' => 'Politiques approuvées par les organes de direction et diffusées selon le besoin '
                        . 'd\'en connaître.',
                    'approval' => true,
                ],
            ],
        ],
        'GV.PO-02' => [
            'it' => 'Riesame delle politiche',
            'en' => 'Policy review',
            'fr' => 'Revue des politiques',
            'nis2' => ['21.2.f'],
            'points' => [
                1 => [
                    'it' => 'Politiche riesaminate almeno ogni anno e dopo cambi normativi, incidenti significativi '
                        . 'o organizzativi.',
                    'en' => 'Policies reviewed at least yearly and after regulatory changes, significant incidents '
                        . 'or reorganisations.',
                    'fr' => 'Politiques revues au moins chaque année et après une évolution réglementaire, un '
                        . 'incident important ou une réorganisation.',
                ],
                2 => [
                    'it' => 'Il riesame verifica almeno la conformità delle politiche alla normativa di sicurezza.',
                    'en' => 'The review checks at least that the policies comply with security regulations.',
                    'fr' => 'La revue vérifie au moins la conformité des politiques à la réglementation de '
                        . 'sécurité.',
                ],
                3 => [
                    'it' => 'Registro aggiornato degli esiti dei riesami.',
                    'en' => 'Up-to-date record of the review results.',
                    'fr' => 'Registre à jour des résultats des revues.',
                    'essential' => true,
                ],
            ],
        ],
        'GV.SC-01' => [
            'it' => 'Sicurezza negli approvvigionamenti',
            'en' => 'Security in procurement',
            'fr' => 'Sécurité des achats',
            'nis2' => ['21.2.d'],
            'points' => [
                1 => [
                    'it' => 'Negli acquisti con impatto sulla sicurezza: coinvolgere l\'organizzazione di sicurezza '
                        . 'e fissare requisiti di sicurezza coerenti con il rischio.',
                    'en' => 'For purchases affecting security: involve the security organisation and set security '
                        . 'requirements matching the risk.',
                    'fr' => 'Pour les achats ayant un effet sur la sécurité : associer l\'organisation de sécurité '
                        . 'et fixer des exigences adaptées au risque.',
                ],
                2 => [
                    'it' => 'I requisiti considerano gli ambiti elencati (affidabilità del fornitore, vulnerabilità, '
                        . 'continuità, dati, subappalto...).',
                    'en' => 'The requirements consider the listed areas (supplier reliability, vulnerabilities, '
                        . 'continuity, data, subcontracting...).',
                    'fr' => 'Les exigences tiennent compte des domaines listés (fiabilité du fournisseur, '
                        . 'vulnérabilités, continuité, données, sous-traitance...).',
                    'essential' => true,
                ],
            ],
        ],
        'GV.SC-02' => [
            'it' => 'Ruoli delle terze parti',
            'en' => 'Third-party roles',
            'fr' => 'Rôles des tiers',
            'nis2' => ['21.2.d'],
            'points' => [
                1 => [
                    'it' => 'Ruoli di sicurezza affidati a personale di terze parti definiti e resi noti.',
                    'en' => 'Security roles given to third-party staff defined and communicated.',
                    'fr' => 'Rôles de sécurité confiés à du personnel de tiers définis et communiqués.',
                ],
                2 => [
                    'it' => 'Questo personale figura nell\'elenco previsto da GV.RR-02, punto 2.',
                    'en' => 'These people appear in the list required by GV.RR-02, point 2.',
                    'fr' => 'Ces personnes figurent dans la liste prévue par GV.RR-02, point 2.',
                ],
            ],
        ],
        'GV.SC-04' => [
            'it' => 'Inventario dei fornitori',
            'en' => 'Supplier inventory',
            'fr' => 'Inventaire des fournisseurs',
            'nis2' => ['21.2.d'],
            'points' => [
                1 => [
                    'it' => 'Inventario aggiornato dei fornitori con impatto sulla sicurezza, con referente e tipo '
                        . 'di fornitura.',
                    'en' => 'Up-to-date inventory of suppliers affecting security, with contact person and type of '
                        . 'supply.',
                    'fr' => 'Inventaire à jour des fournisseurs ayant un effet sur la sécurité, avec contact et type '
                        . 'de fourniture.',
                ],
            ],
        ],
        'GV.SC-05' => [
            'it' => 'Requisiti di sicurezza nei contratti',
            'en' => 'Security requirements in contracts',
            'fr' => 'Exigences de sécurité dans les contrats',
            'nis2' => ['21.2.d'],
            'points' => [
                1 => [
                    'it' => 'Requisiti di sicurezza inseriti in bandi, offerte, contratti e accordi, salvo ragioni '
                        . 'motivate e documentate.',
                    'en' => 'Security requirements written into tenders, offers, contracts and agreements, unless '
                        . 'justified and documented.',
                    'fr' => 'Exigences de sécurité inscrites dans les appels d\'offres, contrats et conventions, '
                        . 'sauf raison motivée et documentée.',
                ],
            ],
        ],
        'GV.SC-07' => [
            'it' => 'Rischio delle forniture',
            'en' => 'Supply risk',
            'fr' => 'Risque lié aux fournitures',
            'nis2' => ['21.2.d'],
            'points' => [
                1 => [
                    'it' => 'Rischio delle forniture valutato e documentato (accessi, dati, interruzione, tempi e '
                        . 'costi di ripristino, ruoli).',
                    'en' => 'Supply risk assessed and documented (access, data, disruption, recovery time and cost, '
                        . 'roles).',
                    'fr' => 'Risque des fournitures évalué et documenté (accès, données, interruption, délais et '
                        . 'coûts de reprise, rôles).',
                ],
                2 => [
                    'it' => 'Verifica periodica e documentata del rispetto dei requisiti contrattuali (GV.SC-05).',
                    'en' => 'Periodic, documented check that suppliers meet the contract requirements (GV.SC-05).',
                    'fr' => 'Vérification périodique et documentée du respect des exigences contractuelles '
                        . '(GV.SC-05).',
                ],
            ],
        ],
        'ID.AM-01' => [
            'it' => 'Inventario dell\'hardware',
            'en' => 'Hardware inventory',
            'fr' => 'Inventaire du matériel',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Inventario aggiornato e approvato degli apparati (IT, IoT, OT, mobili).',
                    'en' => 'Up-to-date, approved inventory of devices (IT, IoT, OT, mobile).',
                    'fr' => 'Inventaire à jour et approuvé des équipements (informatique, IoT, OT, mobiles).',
                ],
            ],
        ],
        'ID.AM-02' => [
            'it' => 'Inventario di software e servizi',
            'en' => 'Software and services inventory',
            'fr' => 'Inventaire des logiciels et services',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Inventario aggiornato e approvato di servizi, sistemi e applicazioni, comprese API.',
                    'en' => 'Up-to-date, approved inventory of services, systems and applications, including APIs.',
                    'fr' => 'Inventaire à jour et approuvé des services, systèmes et applications, API comprises.',
                ],
            ],
        ],
        'ID.AM-03' => [
            'it' => 'Inventario dei flussi di rete',
            'en' => 'Network flow inventory',
            'fr' => 'Inventaire des flux réseau',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Inventario aggiornato e approvato dei flussi di rete verso l\'esterno.',
                    'en' => 'Up-to-date, approved inventory of network flows to and from the outside.',
                    'fr' => 'Inventaire à jour et approuvé des flux réseau avec l\'extérieur.',
                    'essential' => true,
                ],
            ],
        ],
        'ID.AM-04' => [
            'it' => 'Inventario dei servizi dei fornitori',
            'en' => 'Supplier services inventory',
            'fr' => 'Inventaire des services des fournisseurs',
            'nis2' => ['21.2.d', '21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Inventario aggiornato dei servizi informatici dei fornitori, cloud compreso.',
                    'en' => 'Up-to-date inventory of IT services provided by suppliers, cloud included.',
                    'fr' => 'Inventaire à jour des services informatiques des fournisseurs, cloud compris.',
                ],
            ],
        ],
        'ID.RA-01' => [
            'it' => 'Identificazione delle vulnerabilità',
            'en' => 'Vulnerability identification',
            'fr' => 'Identification des vulnérabilités',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Le informazioni raccolte con ID.RA-08 servono a trovare le vulnerabilità dei sistemi.',
                    'en' => 'Information gathered under ID.RA-08 is used to find vulnerabilities in the systems.',
                    'fr' => 'Les informations recueillies au titre d\'ID.RA-08 servent à repérer les vulnérabilités '
                        . 'des systèmes.',
                ],
                2 => [
                    'it' => 'Vulnerability assessment o penetration test periodici e prima della messa in esercizio.',
                    'en' => 'Periodic vulnerability assessments or penetration tests, and before going live.',
                    'fr' => 'Analyses de vulnérabilités ou tests d\'intrusion périodiques et avant la mise en '
                        . 'production.',
                    'essential' => true,
                ],
                3 => [
                    'it' => 'Relazioni sulle attività svolte, con vulnerabilità rilevate e loro impatto.',
                    'en' => 'Reports of these activities, with the vulnerabilities found and their impact.',
                    'fr' => 'Rapports de ces activités, avec les vulnérabilités trouvées et leur impact.',
                    'essential' => true,
                ],
            ],
        ],
        'ID.RA-05' => [
            'it' => 'Valutazione del rischio',
            'en' => 'Risk assessment',
            'fr' => 'Appréciation des risques',
            'nis2' => ['21.2.a'],
            'points' => [
                1 => [
                    'it' => 'Valutazione del rischio documentata (identificazione, analisi, ponderazione), fornitori '
                        . 'compresi.',
                    'en' => 'Documented risk assessment (identification, analysis, evaluation), suppliers included.',
                    'fr' => 'Appréciation des risques documentée (identification, analyse, évaluation), fournisseurs '
                        . 'compris.',
                ],
                2 => [
                    'it' => 'Ripetuta almeno ogni due anni e dopo incidenti significativi o cambiamenti.',
                    'en' => 'Repeated at least every two years and after significant incidents or changes.',
                    'fr' => 'Renouvelée au moins tous les deux ans et après un incident important ou un changement.',
                ],
                3 => [
                    'it' => 'Valutazione approvata dai vertici.',
                    'en' => 'Assessment approved by the management bodies.',
                    'fr' => 'Appréciation approuvée par les organes de direction.',
                    'approval' => true,
                ],
                4 => [
                    'it' => 'Considera minacce interne ed esterne, vulnerabilità aperte e impatti degli incidenti.',
                    'en' => 'Considers internal and external threats, open vulnerabilities and incident impacts.',
                    'fr' => 'Tient compte des menaces internes et externes, des vulnérabilités ouvertes et de '
                        . 'l\'impact des incidents.',
                    'essential' => true,
                ],
            ],
        ],
        'ID.RA-06' => [
            'it' => 'Piano di trattamento dei rischi',
            'en' => 'Risk treatment plan',
            'fr' => 'Plan de traitement des risques',
            'nis2' => ['21.2.a'],
            'points' => [
                1 => [
                    'it' => 'Piano di trattamento documentato: opzioni e priorità, responsabili e tempi, rischi '
                        . 'residui accettati e motivi.',
                    'en' => 'Documented treatment plan: options and priorities, owners and deadlines, accepted '
                        . 'residual risks and reasons.',
                    'fr' => 'Plan de traitement documenté : options et priorités, responsables et délais, risques '
                        . 'résiduels acceptés et motifs.',
                ],
                2 => [
                    'it' => 'Se un requisito della tabella 2 non è attuato: misure compensative descritte nel piano.',
                    'en' => 'If a requirement of table 2 is not met: compensating measures described in the plan.',
                    'fr' => 'Si une exigence du tableau 2 n\'est pas appliquée : mesures compensatoires décrites '
                        . 'dans le plan.',
                ],
                3 => [
                    'it' => 'Piano e accettazione dei rischi residui approvati dai vertici.',
                    'en' => 'Plan and acceptance of residual risks approved by the management bodies.',
                    'fr' => 'Plan et acceptation des risques résiduels approuvés par les organes de direction.',
                    'approval' => true,
                ],
            ],
        ],
        'ID.RA-08' => [
            'it' => 'Gestione delle vulnerabilità',
            'en' => 'Vulnerability management',
            'fr' => 'Gestion des vulnérabilités',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Seguire i canali del CSIRT Italia e degli eventuali CERT e ISAC di settore.',
                    'en' => 'Follow the channels of CSIRT Italia and of any sector CERT and ISAC.',
                    'fr' => 'Suivre les canaux du CSIRT Italia et des éventuels CERT et ISAC sectoriels.',
                ],
                2 => [
                    'it' => 'Vulnerabilità risolte rapidamente, oppure rischio accettato e documentato (ID.RA-06).',
                    'en' => 'Vulnerabilities fixed promptly, or the risk accepted and documented (ID.RA-06).',
                    'fr' => 'Vulnérabilités corrigées rapidement, ou risque accepté et documenté (ID.RA-06).',
                ],
                3 => [
                    'it' => 'Piano di gestione delle vulnerabilità documentato: identificazione, monitoraggio, ruoli.',
                    'en' => 'Documented vulnerability management plan: identification, monitoring, roles.',
                    'fr' => 'Plan de gestion des vulnérabilités documenté : identification, veille, rôles.',
                ],
                4 => [
                    'it' => 'Piano approvato dai vertici.',
                    'en' => 'Plan approved by the management bodies.',
                    'fr' => 'Plan approuvé par les organes de direction.',
                    'approval' => true,
                ],
                5 => [
                    'it' => 'Seguire anche i canali dei fornitori del software critico.',
                    'en' => 'Also follow the channels of the vendors of critical software.',
                    'fr' => 'Suivre aussi les canaux des éditeurs des logiciels critiques.',
                    'essential' => true,
                ],
            ],
        ],
        'ID.IM-01' => [
            'it' => 'Piano di adeguamento e miglioramento',
            'en' => 'Improvement plan',
            'fr' => 'Plan d\'amélioration',
            'nis2' => ['21.2.f'],
            'points' => [
                1 => [
                    'it' => 'Piano di adeguamento, dopo il riesame delle politiche, approvato dai vertici.',
                    'en' => 'Improvement plan, following the policy review, approved by the management bodies.',
                    'fr' => 'Plan de mise en conformité, issu de la revue des politiques, approuvé par les organes '
                        . 'de direction.',
                    'approval' => true,
                ],
                2 => [
                    'it' => 'Relazioni periodiche ai vertici sugli esiti del piano.',
                    'en' => 'Periodic reports to the management bodies on the results of the plan.',
                    'fr' => 'Rapports périodiques aux organes de direction sur les résultats du plan.',
                ],
                3 => [
                    'it' => 'Piano documentato per valutare l\'efficacia delle misure, con metodi di valutazione.',
                    'en' => 'Documented plan to assess the effectiveness of the measures, with assessment methods.',
                    'fr' => 'Plan documenté d\'évaluation de l\'efficacité des mesures, avec ses méthodes.',
                    'essential' => true,
                ],
                4 => [
                    'it' => 'Relazioni periodiche ai vertici sul piano di valutazione dell\'efficacia.',
                    'en' => 'Periodic reports to the management bodies on the effectiveness assessment plan.',
                    'fr' => 'Rapports périodiques aux organes de direction sur le plan d\'évaluation de '
                        . 'l\'efficacité.',
                    'essential' => true,
                ],
            ],
        ],
        'ID.IM-04' => [
            'it' => 'Continuità operativa, disaster recovery e crisi',
            'en' => 'Continuity, disaster recovery and crisis',
            'fr' => 'Continuité, reprise après sinistre et crise',
            'nis2' => ['21.2.c'],
            'points' => [
                1 => [
                    'it' => 'Piano di continuità operativa documentato per i sistemi rilevanti.',
                    'en' => 'Documented business continuity plan for the relevant systems.',
                    'fr' => 'Plan de continuité d\'activité documenté pour les systèmes pertinents.',
                ],
                2 => [
                    'it' => 'Piano di disaster recovery documentato, con ordine e obiettivi di ripristino.',
                    'en' => 'Documented disaster recovery plan, with recovery order and objectives.',
                    'fr' => 'Plan de reprise après sinistre documenté, avec ordre et objectifs de reprise.',
                ],
                3 => [
                    'it' => 'Piano di gestione delle crisi informatiche: ruoli e comunicazione con le autorità.',
                    'en' => 'Cyber crisis management plan: roles and communication with the authorities.',
                    'fr' => 'Plan de gestion des crises cyber : rôles et communication avec les autorités.',
                ],
                4 => [
                    'it' => 'I tre piani sono approvati dai vertici.',
                    'en' => 'The three plans are approved by the management bodies.',
                    'fr' => 'Les trois plans sont approuvés par les organes de direction.',
                    'approval' => true,
                ],
                5 => [
                    'it' => 'Piani riesaminati almeno ogni due anni e dopo incidenti significativi.',
                    'en' => 'Plans reviewed at least every two years and after significant incidents.',
                    'fr' => 'Plans revus au moins tous les deux ans et après un incident important.',
                ],
            ],
        ],
        'PR.AA-01' => [
            'it' => 'Utenze e credenziali',
            'en' => 'Accounts and credentials',
            'fr' => 'Comptes et identifiants',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Tutte le utenze censite e approvate, individuali salvo ragioni tecniche documentate.',
                    'en' => 'All accounts recorded and approved, personal unless documented technical reasons.',
                    'fr' => 'Tous les comptes recensés et approuvés, nominatifs sauf raison technique documentée.',
                ],
                2 => [
                    'it' => 'Credenziali robuste e rinnovate secondo la valutazione del rischio.',
                    'en' => 'Strong credentials, renewed according to the risk assessment.',
                    'fr' => 'Identifiants robustes, renouvelés selon l\'appréciation des risques.',
                ],
                3 => [
                    'it' => 'Revisione periodica delle utenze e dei permessi, revoca ai cambi di personale.',
                    'en' => 'Periodic review of accounts and rights, revoked when staff move or leave.',
                    'fr' => 'Revue périodique des comptes et droits, retrait lors d\'un départ ou d\'une mutation.',
                ],
                4 => [
                    'it' => 'Procedure documentate per i punti 1, 2 e 3.',
                    'en' => 'Documented procedures for points 1, 2 and 3.',
                    'fr' => 'Procédures documentées pour les points 1, 2 et 3.',
                ],
            ],
        ],
        'PR.AA-03' => [
            'it' => 'Autenticazione',
            'en' => 'Authentication',
            'fr' => 'Authentification',
            'nis2' => ['21.2.j', '21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Autenticazione commisurata al rischio (privilegi, criticità, operazioni possibili).',
                    'en' => 'Authentication proportionate to risk (privileges, criticality, possible operations).',
                    'fr' => 'Authentification proportionnée au risque (privilèges, criticité, opérations '
                        . 'possibles).',
                ],
                2 => [
                    'it' => 'Autenticazione multifattore per i sistemi rilevanti, secondo il rischio.',
                    'en' => 'Multi-factor authentication for the relevant systems, according to risk.',
                    'fr' => 'Authentification multifacteur pour les systèmes pertinents, selon le risque.',
                ],
                3 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
            ],
        ],
        'PR.AA-05' => [
            'it' => 'Privilegi e autorizzazioni',
            'en' => 'Privileges and authorisations',
            'fr' => 'Privilèges et autorisations',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Permessi secondo minimo privilegio, separazione delle funzioni e necessità di conoscere.',
                    'en' => 'Rights granted by least privilege, segregation of duties and need to know.',
                    'fr' => 'Droits attribués selon le moindre privilège, la séparation des tâches et le besoin '
                        . 'd\'en connaître.',
                ],
                2 => [
                    'it' => 'Utenze amministrative separate da quelle ordinarie, con credenziali diverse.',
                    'en' => 'Administrator accounts separate from everyday accounts, with different credentials.',
                    'fr' => 'Comptes d\'administration distincts des comptes courants, avec des identifiants '
                        . 'différents.',
                ],
                3 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
            ],
        ],
        'PR.AA-06' => [
            'it' => 'Accesso fisico',
            'en' => 'Physical access',
            'fr' => 'Accès physique',
            'nis2' => ['21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Accesso fisico protetto per i sistemi rilevanti.',
                    'en' => 'Physical access protected for the relevant systems.',
                    'fr' => 'Accès physique protégé pour les systèmes pertinents.',
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                ],
            ],
        ],
        'PR.AT-01' => [
            'it' => 'Formazione del personale',
            'en' => 'Staff training',
            'fr' => 'Formation du personnel',
            'nis2' => ['20.2', '21.2.g'],
            'points' => [
                1 => [
                    'it' => 'Piano di formazione documentato, vertici compresi: contenuti, calendario, verifiche.',
                    'en' => 'Documented training plan, management bodies included: content, schedule, checks.',
                    'fr' => 'Plan de formation documenté, organes de direction compris : contenus, calendrier, '
                        . 'contrôles.',
                ],
                2 => [
                    'it' => 'Piano di formazione approvato dai vertici.',
                    'en' => 'Training plan approved by the management bodies.',
                    'fr' => 'Plan de formation approuvé par les organes de direction.',
                    'approval' => true,
                ],
                3 => [
                    'it' => 'Registro aggiornato delle persone formate, dei contenuti e delle verifiche.',
                    'en' => 'Up-to-date record of trained people, content and checks.',
                    'fr' => 'Registre à jour des personnes formées, des contenus et des contrôles.',
                ],
            ],
        ],
        'PR.AT-02' => [
            'it' => 'Formazione dei ruoli specializzati',
            'en' => 'Training for specialised roles',
            'fr' => 'Formation des rôles spécialisés',
            'nis2' => ['21.2.g'],
            'points' => [
                1 => [
                    'it' => 'Formazione dedicata ai ruoli specializzati, amministratori compresi: configurazione '
                        . 'sicura, minacce, comportamento in caso di eventi.',
                    'en' => 'Dedicated training for specialised roles, administrators included: secure '
                        . 'configuration, threats, what to do on security events.',
                    'fr' => 'Formation dédiée aux rôles spécialisés, administrateurs compris : configuration sûre, '
                        . 'menaces, conduite à tenir lors d\'un événement.',
                    'essential' => true,
                ],
                2 => [
                    'it' => 'Registro aggiornato di questa formazione.',
                    'en' => 'Up-to-date record of this training.',
                    'fr' => 'Registre à jour de cette formation.',
                    'essential' => true,
                ],
            ],
        ],
        'PR.DS-01' => [
            'it' => 'Dati a riposo',
            'en' => 'Data at rest',
            'fr' => 'Données au repos',
            'nis2' => ['21.2.h'],
            'points' => [
                1 => [
                    'it' => 'Cifratura dei dati su dispositivi portatili e supporti rimovibili, salvo ragioni '
                        . 'documentate.',
                    'en' => 'Encryption of data on portable devices and removable media, unless documented reasons.',
                    'fr' => 'Chiffrement des données des appareils portables et supports amovibles, sauf raison '
                        . 'documentée.',
                ],
                2 => [
                    'it' => 'Esecuzione automatica dei supporti rimovibili disattivata e scansione antimalware.',
                    'en' => 'Autorun of removable media disabled and media scanned for malware.',
                    'fr' => 'Exécution automatique des supports amovibles désactivée et analyse antimalware.',
                ],
                3 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
            ],
        ],
        'PR.DS-02' => [
            'it' => 'Dati in transito',
            'en' => 'Data in transit',
            'fr' => 'Données en transit',
            'nis2' => ['21.2.h', '21.2.j'],
            'points' => [
                1 => [
                    'it' => 'Cifratura allo stato dell\'arte dei dati scambiati con l\'esterno, voce e video compresi.',
                    'en' => 'State-of-the-art encryption of data exchanged with the outside, voice and video '
                        . 'included.',
                    'fr' => 'Chiffrement à l\'état de l\'art des données échangées avec l\'extérieur, voix et vidéo '
                        . 'comprises.',
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                ],
            ],
        ],
        'PR.DS-11' => [
            'it' => 'Backup',
            'en' => 'Backups',
            'fr' => 'Sauvegardes',
            'nis2' => ['21.2.c'],
            'points' => [
                1 => [
                    'it' => 'Backup periodici di dati e configurazioni, con copie offline per i sistemi rilevanti.',
                    'en' => 'Periodic backups of data and configurations, with offline copies for the relevant '
                        . 'systems.',
                    'fr' => 'Sauvegardes périodiques des données et configurations, avec copies hors ligne pour les '
                        . 'systèmes pertinents.',
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                ],
                3 => [
                    'it' => 'Riservatezza e integrità dei backup garantite (protezione fisica o cifratura).',
                    'en' => 'Confidentiality and integrity of backups ensured (physical protection or encryption).',
                    'fr' => 'Confidentialité et intégrité des sauvegardes assurées (protection physique ou '
                        . 'chiffrement).',
                    'essential' => true,
                ],
                4 => [
                    'it' => 'Test di ripristino periodici dei backup.',
                    'en' => 'Periodic restore tests of the backups.',
                    'fr' => 'Tests de restauration périodiques des sauvegardes.',
                    'essential' => true,
                ],
                5 => [
                    'it' => 'Procedure documentate per i punti 3 e 4.',
                    'en' => 'Documented procedures for points 3 and 4.',
                    'fr' => 'Procédures documentées pour les points 3 et 4.',
                    'essential' => true,
                ],
            ],
        ],
        'PR.PS-01' => [
            'it' => 'Configurazioni sicure',
            'en' => 'Secure configurations',
            'fr' => 'Configurations sécurisées',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Configurazioni di riferimento sicure (hardening) definite ed elencate per i sistemi '
                        . 'rilevanti.',
                    'en' => 'Secure (hardened) baseline configurations defined and listed for the relevant systems.',
                    'fr' => 'Configurations de référence sécurisées (durcissement) définies et listées pour les '
                        . 'systèmes pertinents.',
                    'essential' => true,
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                    'essential' => true,
                ],
            ],
        ],
        'PR.PS-02' => [
            'it' => 'Aggiornamento del software',
            'en' => 'Software updates',
            'fr' => 'Mises à jour logicielles',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Solo software ancora coperto da aggiornamenti di sicurezza, salvo ragioni documentate.',
                    'en' => 'Only software still covered by security updates, unless documented reasons.',
                    'fr' => 'Uniquement des logiciels encore couverts par des mises à jour de sécurité, sauf '
                        . 'raison documentée.',
                ],
                2 => [
                    'it' => 'Aggiornamenti di sicurezza installati senza ritardo, secondo il piano ID.RA-08.',
                    'en' => 'Security updates installed without delay, following the ID.RA-08 plan.',
                    'fr' => 'Mises à jour de sécurité installées sans retard, selon le plan ID.RA-08.',
                ],
                3 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
                4 => [
                    'it' => 'Aggiornamenti del software critico provati in ambiente di test prima della produzione.',
                    'en' => 'Updates of critical software tested in a test environment before production.',
                    'fr' => 'Mises à jour des logiciels critiques testées dans un environnement de test avant la '
                        . 'production.',
                    'essential' => true,
                ],
                5 => [
                    'it' => 'Procedure documentate per il punto 4.',
                    'en' => 'Documented procedures for point 4.',
                    'fr' => 'Procédures documentées pour le point 4.',
                    'essential' => true,
                ],
            ],
        ],
        'PR.PS-03' => [
            'it' => 'Manutenzione e dismissione dell\'hardware',
            'en' => 'Hardware maintenance and disposal',
            'fr' => 'Maintenance et mise au rebut du matériel',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Procedure per trasferire e dismettere in sicurezza i supporti di memorizzazione.',
                    'en' => 'Procedures to move and dispose of storage devices securely.',
                    'fr' => 'Procédures pour déplacer et mettre au rebut les supports de stockage en sécurité.',
                    'essential' => true,
                ],
                2 => [
                    'it' => 'Registri delle manutenzioni hardware dei sistemi rilevanti.',
                    'en' => 'Hardware maintenance logs for the relevant systems.',
                    'fr' => 'Registres de maintenance du matériel des systèmes pertinents.',
                    'essential' => true,
                ],
            ],
        ],
        'PR.PS-04' => [
            'it' => 'Registrazione dei log',
            'en' => 'Logging',
            'fr' => 'Journalisation',
            'nis2' => ['21.2.b'],
            'points' => [
                1 => [
                    'it' => 'Registrare gli accessi remoti e quelli con utenze amministrative.',
                    'en' => 'Log remote access and access with administrator accounts.',
                    'fr' => 'Journaliser les accès à distance et ceux faits avec un compte d\'administration.',
                ],
                2 => [
                    'it' => 'Raccogliere e conservare in sicurezza i log utili al monitoraggio, se possibile in modo '
                        . 'centralizzato.',
                    'en' => 'Collect and keep securely the logs needed for monitoring, centrally if possible.',
                    'fr' => 'Collecter et conserver en sécurité les journaux utiles à la surveillance, de façon '
                        . 'centralisée si possible.',
                ],
                3 => [
                    'it' => 'Tempi di conservazione dei log definiti secondo il rischio.',
                    'en' => 'Log retention periods set according to risk.',
                    'fr' => 'Durées de conservation des journaux fixées selon le risque.',
                ],
                4 => [
                    'it' => 'Procedure documentate per i punti 1 e 2.',
                    'en' => 'Documented procedures for points 1 and 2.',
                    'fr' => 'Procédures documentées pour les points 1 et 2.',
                ],
            ],
        ],
        'PR.PS-06' => [
            'it' => 'Sviluppo sicuro',
            'en' => 'Secure development',
            'fr' => 'Développement sécurisé',
            'nis2' => ['21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Pratiche di sviluppo sicuro del codice adottate e documentate.',
                    'en' => 'Secure coding practices adopted and documented.',
                    'fr' => 'Pratiques de développement sécurisé adoptées et documentées.',
                ],
            ],
        ],
        'PR.IR-01' => [
            'it' => 'Reti e accessi remoti',
            'en' => 'Networks and remote access',
            'fr' => 'Réseaux et accès à distance',
            'nis2' => ['21.2.e', '21.2.i'],
            'points' => [
                1 => [
                    'it' => 'Attività consentite da remoto definite, con misure di sicurezza per l\'accesso.',
                    'en' => 'Activities allowed remotely defined, with security measures for access.',
                    'fr' => 'Activités autorisées à distance définies, avec des mesures de sécurité pour l\'accès.',
                ],
                2 => [
                    'it' => 'Elenco aggiornato dei sistemi accessibili da remoto e delle modalità di accesso.',
                    'en' => 'Up-to-date list of systems reachable remotely and how they are reached.',
                    'fr' => 'Liste à jour des systèmes accessibles à distance et des modes d\'accès.',
                ],
                3 => [
                    'it' => 'Sistemi perimetrali (es. firewall) presenti, aggiornati e ben configurati.',
                    'en' => 'Perimeter systems (e.g. firewalls) in place, updated and well configured.',
                    'fr' => 'Systèmes périmétriques (pare-feu par exemple) en place, à jour et bien configurés.',
                ],
                4 => [
                    'it' => 'Procedure documentate per i punti 2 e 3.',
                    'en' => 'Documented procedures for points 2 and 3.',
                    'fr' => 'Procédures documentées pour les points 2 et 3.',
                ],
            ],
        ],
        'PR.IR-03' => [
            'it' => 'Comunicazioni di emergenza',
            'en' => 'Emergency communications',
            'fr' => 'Communications d\'urgence',
            'nis2' => ['21.2.j', '21.2.c'],
            'points' => [
                1 => [
                    'it' => 'Sistemi di comunicazione di emergenza protetti, secondo il rischio.',
                    'en' => 'Secured emergency communication systems, according to risk.',
                    'fr' => 'Systèmes de communication d\'urgence sécurisés, selon le risque.',
                    'essential' => true,
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                    'essential' => true,
                ],
            ],
        ],
        'DE.CM-01' => [
            'it' => 'Monitoraggio delle reti',
            'en' => 'Network monitoring',
            'fr' => 'Surveillance des réseaux',
            'nis2' => ['21.2.b'],
            'points' => [
                1 => [
                    'it' => 'Strumenti tecnici per rilevare in tempo gli incidenti significativi sui sistemi '
                        . 'rilevanti.',
                    'en' => 'Technical tools to detect significant incidents quickly on the relevant systems.',
                    'fr' => 'Outils techniques pour détecter rapidement les incidents importants sur les systèmes '
                        . 'pertinents.',
                ],
                2 => [
                    'it' => 'Livelli di servizio attesi definiti e documentati, anche per rilevare gli incidenti.',
                    'en' => 'Expected service levels defined and documented, also to detect incidents.',
                    'fr' => 'Niveaux de service attendus définis et documentés, aussi pour détecter les incidents.',
                ],
                3 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                ],
                4 => [
                    'it' => 'Analisi e filtraggio del traffico in ingresso, posta elettronica compresa.',
                    'en' => 'Analysis and filtering of incoming traffic, email included.',
                    'fr' => 'Analyse et filtrage du trafic entrant, messagerie comprise.',
                    'essential' => true,
                ],
                5 => [
                    'it' => 'Monitorare accessi remoti, sistemi perimetrali, eventi amministrativi e accessi riusciti '
                        . 'o falliti.',
                    'en' => 'Monitor remote access, perimeter systems, administrative events and successful or '
                        . 'failed access.',
                    'fr' => 'Surveiller les accès à distance, les systèmes périmétriques, les événements '
                        . 'd\'administration et les accès réussis ou échoués.',
                    'essential' => true,
                ],
                6 => [
                    'it' => 'Parametri definiti e monitorati per rilevare accessi non autorizzati o abusi di '
                        . 'privilegi.',
                    'en' => 'Parameters defined and monitored to detect unauthorised access or privilege abuse.',
                    'fr' => 'Indicateurs définis et suivis pour détecter les accès non autorisés ou les abus de '
                        . 'privilèges.',
                    'essential' => true,
                ],
                7 => [
                    'it' => 'Procedure documentate per i punti 4 e 5.',
                    'en' => 'Documented procedures for points 4 and 5.',
                    'fr' => 'Procédures documentées pour les points 4 et 5.',
                    'essential' => true,
                ],
            ],
        ],
        'DE.CM-09' => [
            'it' => 'Protezione degli endpoint',
            'en' => 'Endpoint protection',
            'fr' => 'Protection des postes',
            'nis2' => ['21.2.b', '21.2.e'],
            'points' => [
                1 => [
                    'it' => 'Protezione degli endpoint contro il codice malevolo, aggiornata e ben configurata.',
                    'en' => 'Endpoint protection against malicious code, updated and well configured.',
                    'fr' => 'Protection des postes contre les codes malveillants, à jour et bien configurée.',
                ],
                2 => [
                    'it' => 'Procedure documentate per il punto 1.',
                    'en' => 'Documented procedures for point 1.',
                    'fr' => 'Procédures documentées pour le point 1.',
                ],
            ],
        ],
        'RS.MA-01' => [
            'it' => 'Piano di gestione degli incidenti',
            'en' => 'Incident management plan',
            'fr' => 'Plan de gestion des incidents',
            'nis2' => ['21.2.b', '23.4.a'],
            'points' => [
                1 => [
                    'it' => 'Piano di gestione e notifica al CSIRT Italia (art. 25 del decreto NIS): fasi, ruoli, '
                        . 'contatti, comunicazione, modelli.',
                    'en' => 'Plan for handling incidents and notifying CSIRT Italia (art. 25 of the NIS decree): '
                        . 'phases, roles, contacts, communication, templates.',
                    'fr' => 'Plan de gestion et de notification au CSIRT Italia (art. 25 du décret NIS) : phases, '
                        . 'rôles, contacts, communication, modèles.',
                ],
                2 => [
                    'it' => 'Piano approvato dai vertici.',
                    'en' => 'Plan approved by the management bodies.',
                    'fr' => 'Plan approuvé par les organes de direction.',
                    'approval' => true,
                ],
                3 => [
                    'it' => 'Piano riesaminato almeno ogni due anni e dopo incidenti significativi, con le lezioni '
                        . 'apprese.',
                    'en' => 'Plan reviewed at least every two years and after significant incidents, with lessons '
                        . 'learned.',
                    'fr' => 'Plan revu au moins tous les deux ans et après un incident important, avec les retours '
                        . 'd\'expérience.',
                ],
            ],
        ],
        'RS.CO-02' => [
            'it' => 'Comunicazione degli incidenti',
            'en' => 'Incident communication',
            'fr' => 'Communication sur les incidents',
            'nis2' => ['21.2.b', '23.4.a'],
            'points' => [
                1 => [
                    'it' => 'Procedure per informare senza ritardo i destinatari dei servizi su incidenti e minacce '
                        . 'significativi.',
                    'en' => 'Procedures to inform service recipients without delay about significant incidents and '
                        . 'threats.',
                    'fr' => 'Procédures pour informer sans retard les destinataires des services des incidents et '
                        . 'menaces importants.',
                ],
                2 => [
                    'it' => 'Procedure per informare il pubblico quando l\'ACN lo impone.',
                    'en' => 'Procedures to inform the public when ACN requires it.',
                    'fr' => 'Procédures pour informer le public lorsque l\'ACN l\'impose.',
                ],
            ],
        ],
        'RC.RP-01' => [
            'it' => 'Procedure di ripristino',
            'en' => 'Recovery procedures',
            'fr' => 'Procédures de rétablissement',
            'nis2' => ['21.2.b', '21.2.c'],
            'points' => [
                1 => [
                    'it' => 'Procedure documentate per ripristinare il funzionamento dei sistemi colpiti da un '
                        . 'incidente.',
                    'en' => 'Documented procedures to restore normal operation of the systems hit by an incident.',
                    'fr' => 'Procédures documentées pour rétablir le fonctionnement des systèmes touchés par un '
                        . 'incident.',
                ],
            ],
        ],
        'RC.CO-03' => [
            'it' => 'Comunicazione sul ripristino',
            'en' => 'Recovery communication',
            'fr' => 'Communication sur le rétablissement',
            'nis2' => ['21.2.c'],
            'points' => [
                1 => [
                    'it' => 'Procedure per comunicare alle strutture interne le attività di ripristino.',
                    'en' => 'Procedures to tell internal units about recovery activities.',
                    'fr' => 'Procédures pour informer les services internes des actions de rétablissement.',
                    'essential' => true,
                ],
            ],
        ],
    ];

    /**
     * Allegato applicable à une catégorie d'entité (1 importante, 2 essentielle), 0 si inconnue.
     */
    public static function annexFor(string $category): int
    {
        return match ($category) {
            self::CATEGORY_IMPORTANT => 1,
            self::CATEGORY_ESSENTIAL => 2,
            default                  => 0,
        };
    }

    /** Le point fait-il partie du catalogue de la catégorie ? */
    public static function inScope(string $code, int $point, string $category): bool
    {
        $entry = self::MEASURES[$code]['points'][$point] ?? null;
        if ($entry === null || self::annexFor($category) === 0) {
            return false;
        }

        return $category === self::CATEGORY_ESSENTIAL || empty($entry['essential']);
    }

    /**
     * Tous les points (des deux allegati), dans l'ordre : liste de [code, point].
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function allPoints(): array
    {
        $points = [];
        foreach (self::MEASURES as $code => $measure) {
            foreach (array_keys($measure['points']) as $point) {
                $points[] = [$code, $point];
            }
        }

        return $points;
    }

    /**
     * Points du catalogue d'une catégorie : liste de [code, point].
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function pointsFor(string $category): array
    {
        return array_values(array_filter(
            self::allPoints(),
            static fn (array $entry): bool => self::inScope($entry[0], $entry[1], $category)
        ));
    }

    /**
     * Mesures du catalogue d'une catégorie (une mesure de l'allegato 2 seulement en est absente pour
     * une entité importante).
     *
     * @return list<string>
     */
    public static function measuresFor(string $category): array
    {
        $codes = [];
        foreach (self::pointsFor($category) as [$code]) {
            $codes[$code] = true;
        }

        return array_keys($codes);
    }

    /** Fonction du Framework nazionale (« GV ») d'un code (« GV.RR-02 »). */
    public static function functionOf(string $code): string
    {
        return substr($code, 0, 2);
    }

    public static function measureTitle(string $code, ?string $language = null): string
    {
        $measure = self::MEASURES[$code] ?? null;

        return $measure === null ? $code : CatalogLanguage::pick($measure, '', $language);
    }

    public static function pointSummary(string $code, int $point, ?string $language = null): string
    {
        $entry = self::MEASURES[$code]['points'][$point] ?? null;

        return $entry === null ? '' : CatalogLanguage::pick($entry, '', $language);
    }

    public static function functionName(string $function, ?string $language = null): string
    {
        $entry = self::FUNCTIONS[$function] ?? null;

        return $entry === null ? $function : CatalogLanguage::pick($entry, '', $language);
    }

    public static function requiresApproval(string $code, int $point): bool
    {
        return !empty(self::MEASURES[$code]['points'][$point]['approval']);
    }

    public static function isEssentialOnly(string $code, int $point): bool
    {
        return !empty(self::MEASURES[$code]['points'][$point]['essential']);
    }

    /** Référence lisible du point : « DE.CM-01, punto 2 ». */
    public static function reference(string $code, int $point): string
    {
        return sprintf('%s, punto %d', $code, $point);
    }

    /**
     * Allegati qui contiennent le point (1 et 2, ou 2 seul).
     *
     * @return list<int>
     */
    public static function annexesOf(string $code, int $point): array
    {
        if (!isset(self::MEASURES[$code]['points'][$point])) {
            return [];
        }

        return self::isEssentialOnly($code, $point) ? [2] : [1, 2];
    }

    /**
     * Mesures de l'Annexe A ISO 27001 (« A.5.1 ») que le NIST relie à la sous-catégorie.
     *
     * @return list<string>
     */
    public static function annexAFor(string $code): array
    {
        $controls = [];
        foreach (ControlCrosswalkDefaults::CROSSWALK as $annexA => $mapping) {
            if (in_array($code, $mapping['nist_csf'] ?? [], true)) {
                $controls[] = 'A.' . $annexA;
            }
        }

        return $controls;
    }

    /**
     * Exigences NIS2 (UE) liées, avec leur texte dans la langue retenue.
     *
     * @return array<string, string>
     */
    public static function nis2For(string $code, ?string $language = null): array
    {
        $refs = [];
        foreach (self::MEASURES[$code]['nis2'] ?? [] as $key) {
            $requirement = Nis2CatalogDefaults::REQUIREMENTS[$key] ?? null;
            if ($requirement !== null) {
                $refs[$key] = CatalogLanguage::pick($requirement, '', $language);
            }
        }

        return $refs;
    }

    /** Texte officiel (anglais) de la sous-catégorie NIST CSF 2.0 de même code. */
    public static function nistText(string $code): string
    {
        return NistCsfCatalogDefaults::SUBCATEGORIES[$code]['text'] ?? '';
    }
}

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
 * Issue #878 : référentiel de mesures de MonServiceSécurisé (ANSSI, service public numérique d'aide à
 * l'homologation de sécurité), version 2 : 135 mesures en 29 thèmes, dont 6 mesures RGPD issues des
 * recommandations de la CNIL. Les mesures sont rangées ici par thème (préfixe de leur identifiant),
 * chaque thème dans la catégorie MonServiceSécurisé de la plupart de ses mesures (gouvernance,
 * protection, défense, résilience).
 *
 * Droits : le référentiel est publié dans le code source de MonServiceSécurisé
 * (github.com/betagouv/mon-service-securise, fichier donneesReferentielMesuresV2.ts), sous licence
 * Apache 2.0. Seuls les identifiants des mesures sont repris ; l'objet de chaque thème est résumé
 * avec les mots de ce projet. La correspondance `annex_a` avec ISO/IEC 27001:2022 est indicative et
 * établie par ce projet ; une liste `annex_a` vide signale une exigence portée par les chapitres 4 à
 * 10 de la norme (appréciation des risques), sans mesure de l'Annexe A. Mention affichée sous la
 * carte de templates/referentiels.html.twig.
 */
final class MonServiceSecuriseCatalogDefaults
{
    public const SOURCE_URL = 'https://monservicesecurise.cyber.gouv.fr/';

    /**
     * @var array<string, array{fr: string, en: string}> Catégorie MonServiceSécurisé => intitulé.
     */
    public const CATEGORIES = [
        'gouvernance' => ['fr' => 'Gouvernance', 'en' => 'Governance'],
        'protection'  => ['fr' => 'Protection', 'en' => 'Protection'],
        'defense'     => ['fr' => 'Défense', 'en' => 'Defence'],
        'resilience'  => ['fr' => 'Résilience', 'en' => 'Resilience'],
    ];

    /** Modules du plugin qu'un thème peut citer (liens résolus par front/referentiels.php). */
    public const MODULES = [
        'businessprocesses', 'processing', 'dpia', 'requests', 'audits', 'nonconformities', 'policies',
        'suppliers', 'networkflows', 'trainings', 'accessreviews', 'accounthygiene', 'vulnerabilities',
        'risks', 'riskcatalog', 'incidents', 'impactanalysis', 'exercises',
    ];

    /**
     * Thème (préfixe des identifiants) => catégorie, identifiants des mesures, objet résumé par ce
     * projet (fr, en), mesures de l'Annexe A ISO 27001 concernées et modules du plugin.
     *
     * @var array<string, array{category: string, measures: list<string>, fr: string, en: string,
     *     annex_a: list<string>, modules: list<string>}>
     */
    public const THEMES = [
        'RECENSEMENT' => [
            'category' => 'gouvernance',
            'measures' => ['RECENSEMENT.1', 'RECENSEMENT.2', 'RECENSEMENT.3'],
            'fr'       => 'Inventaire des services, données, équipements et logiciels à protéger ; ne garder que '
                . 'les données utiles.',
            'en'       => 'Inventory of the services, data, devices and software to protect; keep only the data '
                . 'that is needed.',
            'annex_a'  => ['A.5.9', 'A.5.12', 'A.5.34'],
            'modules'  => ['businessprocesses', 'processing'],
        ],
        'CONFORMITE'  => [
            'category' => 'gouvernance',
            'measures' => ['CONFORMITE.1', 'CONFORMITE.3'],
            'fr'       => 'Analyse des écarts aux textes et normes applicables, puis plan d\'action suivi.',
            'en'       => 'Gap analysis against the applicable laws and standards, then a tracked action plan.',
            'annex_a'  => ['A.5.31', 'A.5.35', 'A.5.36'],
            'modules'  => ['audits', 'nonconformities'],
        ],
        'PSSI'        => [
            'category' => 'gouvernance',
            'measures' => ['PSSI.5'],
            'fr'       => 'Revue au moins annuelle des politiques et procédures de sécurité.',
            'en'       => 'At least yearly review of the security policies and procedures.',
            'annex_a'  => ['A.5.1', 'A.5.37'],
            'modules'  => ['policies'],
        ],
        'CONTRAT'     => [
            'category' => 'gouvernance',
            'measures' => ['CONTRAT.1', 'CONTRAT.2'],
            'fr'       => 'Engagements de sécurité des prestataires, puis contrôle de leur respect.',
            'en'       => 'Security commitments of the service providers, then checks that they are met.',
            'annex_a'  => ['A.5.19', 'A.5.20', 'A.5.22'],
            'modules'  => ['suppliers'],
        ],
        'DEV'         => [
            'category' => 'gouvernance',
            'measures' => ['DEV.1'],
            'fr'       => 'Bonnes pratiques de développement sécurisé.',
            'en'       => 'Secure development practices.',
            'annex_a'  => ['A.8.25', 'A.8.28'],
            'modules'  => [],
        ],
        'ECOSYSTEME'  => [
            'category' => 'gouvernance',
            'measures' => [
                'ECOSYSTEME.1', 'ECOSYSTEME.2', 'ECOSYSTEME.3', 'ECOSYSTEME.4', 'ECOSYSTEME.5', 'ECOSYSTEME.6',
            ],
            'fr'       => 'Prestataires et interconnexions recensés, hébergement en Europe ou qualifié '
                . 'SecNumCloud, noms de domaine de confiance et protégés contre l\'usurpation.',
            'en'       => 'Listed providers and interconnections, hosting in Europe or SecNumCloud qualified, '
                . 'trusted domain names protected against look-alikes.',
            'annex_a'  => ['A.5.19', 'A.5.23', 'A.5.14'],
            'modules'  => ['suppliers', 'networkflows'],
        ],
        'RH'          => [
            'category' => 'gouvernance',
            'measures' => ['RH.2', 'RH.4'],
            'fr'       => 'Sensibilisation de tous les intervenants ; arrivées, départs et changements de poste.',
            'en'       => 'Awareness for everyone involved; joiners, leavers and job changes.',
            'annex_a'  => ['A.6.3', 'A.6.5', 'A.5.18'],
            'modules'  => ['trainings', 'accessreviews'],
        ],
        'CARTO'       => [
            'category' => 'gouvernance',
            'measures' => ['CARTO.2'],
            'fr'       => 'Dossier d\'architecture du système, tenu à jour.',
            'en'       => 'Up-to-date architecture file of the system.',
            'annex_a'  => ['A.8.27', 'A.5.37'],
            'modules'  => ['networkflows'],
        ],
        'RGPD'        => [
            'category' => 'gouvernance',
            'measures' => ['RGPD.1', 'RGPD.2', 'RGPD.3', 'RGPD.4', 'RGPD.5', 'RGPD.6'],
            'fr'       => 'Données personnelles (CNIL) : registre, minimisation, durées de conservation, '
                . 'information et droits des personnes, besoin d\'une analyse d\'impact.',
            'en'       => 'Personal data (CNIL): record of processing, minimisation, retention periods, '
                . 'information and rights of individuals, need for an impact assessment.',
            'annex_a'  => ['A.5.34'],
            'modules'  => ['processing', 'dpia', 'requests'],
        ],
        'RISQUE'      => [
            'category' => 'gouvernance',
            'measures' => ['RISQUE.1', 'RISQUE.2'],
            'fr'       => 'Analyse de risques, rapide ou approfondie, mise à jour au moins tous les trois ans '
                . '(chapitres 6.1 et 8.2 d\'ISO 27001).',
            'en'       => 'Risk analysis, quick or in-depth, updated at least every three years (clauses 6.1 '
                . 'and 8.2 of ISO 27001).',
            'annex_a'  => [],
            'modules'  => ['risks', 'riskcatalog'],
        ],
        'AUDIT'       => [
            'category' => 'gouvernance',
            'measures' => ['AUDIT.1', 'AUDIT.2', 'AUDIT.3', 'AUDIT.4', 'AUDIT.5', 'AUDIT.6', 'AUDIT.7'],
            'fr'       => 'Programme d\'audits : code, tests d\'intrusion, configuration, architecture, '
                . 'analyse automatique du code.',
            'en'       => 'Audit programme: code, penetration tests, configuration, architecture, automated '
                . 'code analysis.',
            'annex_a'  => ['A.5.35', 'A.8.8', 'A.8.29', 'A.8.34'],
            'modules'  => ['audits', 'vulnerabilities'],
        ],
        'DONNEES'     => [
            'category' => 'protection',
            'measures' => ['DONNEES.1', 'DONNEES.2'],
            'fr'       => 'Chiffrement des données stockées, anonymisation des données d\'usage.',
            'en'       => 'Encryption of stored data, anonymisation of usage data.',
            'annex_a'  => ['A.8.24', 'A.8.11'],
            'modules'  => ['processing'],
        ],
        'MCO_MCS'     => [
            'category' => 'protection',
            'measures' => [
                'MCO_MCS.1', 'MCO_MCS.3', 'MCO_MCS.4', 'MCO_MCS.5', 'MCO_MCS.6', 'MCO_MCS.7', 'MCO_MCS.10',
                'MCO_MCS.11', 'MCO_MCS.12', 'MCO_MCS.14', 'MCO_MCS.15', 'MCO_MCS.16', 'MCO_MCS.17',
            ],
            'fr'       => 'Maintien en condition de sécurité : veille, correctifs sans retard, logiciels '
                . 'maintenus et officiels, pare-feu, effacement avant réemploi ou mise au rebut.',
            'en'       => 'Security maintenance: monitoring of threats, patches without delay, supported and '
                . 'official software, firewall, wiping before reuse or disposal.',
            'annex_a'  => ['A.8.8', 'A.8.19', 'A.7.14', 'A.8.10'],
            'modules'  => ['vulnerabilities'],
        ],
        'PHYS'        => [
            'category' => 'protection',
            'measures' => ['PHYS.1', 'PHYS.2'],
            'fr'       => 'Accès physique aux locaux et salles techniques limité et contrôlé.',
            'en'       => 'Limited and controlled physical access to the premises and technical rooms.',
            'annex_a'  => ['A.7.1', 'A.7.2'],
            'modules'  => [],
        ],
        'CLOISON'     => [
            'category' => 'protection',
            'measures' => ['CLOISON.1', 'CLOISON.2', 'CLOISON.3', 'CLOISON.4', 'CLOISON.5', 'CLOISON.6'],
            'fr'       => 'Cloisonnement du système, passerelles et seules interconnexions nécessaires.',
            'en'       => 'Segregation of the system, gateways and only the interconnections that are needed.',
            'annex_a'  => ['A.8.22'],
            'modules'  => ['networkflows'],
        ],
        'FILTRE'      => [
            'category' => 'protection',
            'measures' => ['FILTRE.1', 'FILTRE.2', 'FILTRE.3', 'FILTRE.4', 'FILTRE.5', 'FILTRE.6', 'FILTRE.7'],
            'fr'       => 'Filtrage documenté et revu, protection contre le déni de service, pare-feu applicatif, '
                . 'filtrage sur les postes, limitation du débit des requêtes.',
            'en'       => 'Documented and reviewed filtering, denial of service protection, web application '
                . 'firewall, filtering on workstations, request rate limiting.',
            'annex_a'  => ['A.8.20', 'A.8.21', 'A.8.22'],
            'modules'  => ['networkflows'],
        ],
        'MAIL'        => [
            'category' => 'protection',
            'measures' => ['MAIL.1', 'MAIL.2'],
            'fr'       => 'Filtrage du pourriel et de l\'hameçonnage, authenticité des messages du domaine.',
            'en'       => 'Spam and phishing filtering, authenticity of the messages sent for the domain.',
            'annex_a'  => ['A.5.14', 'A.8.7', 'A.8.21'],
            'modules'  => [],
        ],
        'DISTANCE'    => [
            'category' => 'protection',
            'measures' => ['DISTANCE.1', 'DISTANCE.2', 'DISTANCE.3', 'DISTANCE.4'],
            'fr'       => 'Accès à distance authentifiés, chiffrés et à plusieurs facteurs, disques des '
                . 'équipements nomades chiffrés.',
            'en'       => 'Remote access authenticated, encrypted and multi-factor, disks of mobile devices '
                . 'encrypted.',
            'annex_a'  => ['A.6.7', 'A.8.5', 'A.8.24'],
            'modules'  => [],
        ],
        'MALWARE'     => [
            'category' => 'protection',
            'measures' => ['MALWARE.3', 'MALWARE.4', 'MALWARE.5', 'MALWARE.6'],
            'fr'       => 'Analyse des fichiers reçus, antivirus ou EDR à jour, traitement des alertes.',
            'en'       => 'Scanning of received files, up-to-date antivirus or EDR, handling of the alerts.',
            'annex_a'  => ['A.8.7'],
            'modules'  => [],
        ],
        'AUTH'        => [
            'category' => 'protection',
            'measures' => [
                'AUTH.1', 'AUTH.2', 'AUTH.3', 'AUTH.4', 'AUTH.5', 'AUTH.6', 'AUTH.7', 'AUTH.8', 'AUTH.9',
                'AUTH.10', 'AUTH.11', 'AUTH.12',
            ],
            'fr'       => 'Authentification : secrets par défaut changés, comptes partagés maîtrisés, '
                . 'complexité, verrouillage, déconnexion automatique, stockage protégé des mots de passe.',
            'en'       => 'Authentication: default secrets changed, shared accounts under control, complexity, '
                . 'lockout, automatic logout, protected storage of passwords.',
            'annex_a'  => ['A.5.17', 'A.8.5'],
            'modules'  => ['accounthygiene'],
        ],
        'DROITS'      => [
            'category' => 'protection',
            'measures' => ['DROITS.1', 'DROITS.2', 'DROITS.3'],
            'fr'       => 'Droits limités au nécessaire pour les personnes et les processus, revus chaque année.',
            'en'       => 'Rights limited to what is needed for people and processes, reviewed every year.',
            'annex_a'  => ['A.5.15', 'A.5.18', 'A.8.3'],
            'modules'  => ['accessreviews'],
        ],
        'ID'          => [
            'category' => 'protection',
            'measures' => ['ID.1', 'ID.2', 'ID.3', 'ID.4'],
            'fr'       => 'Comptes individuels, comptes inutiles désactivés ou supprimés, revue annuelle.',
            'en'       => 'Individual accounts, unneeded accounts disabled or removed, yearly review.',
            'annex_a'  => ['A.5.16', 'A.5.18'],
            'modules'  => ['accounthygiene', 'accessreviews'],
        ],
        'ANNUAIRE'    => [
            'category' => 'protection',
            'measures' => ['ANNUAIRE.1', 'ANNUAIRE.2', 'ANNUAIRE.3', 'ANNUAIRE.5'],
            'fr'       => 'Cœur de confiance (annuaires) recensé, administré par des moyens dédiés, '
                . 'configuration revue chaque année.',
            'en'       => 'Core of trust (directories) listed, administered with dedicated means, configuration '
                . 'reviewed every year.',
            'annex_a'  => ['A.8.2', 'A.8.9'],
            'modules'  => ['accounthygiene'],
        ],
        'COMPADMIN'   => [
            'category' => 'protection',
            'measures' => [
                'COMPADMIN.1', 'COMPADMIN.2', 'COMPADMIN.3', 'COMPADMIN.4', 'COMPADMIN.5', 'COMPADMIN.6',
                'COMPADMIN.7', 'COMPADMIN.9',
            ],
            'fr'       => 'Comptes d\'administration dédiés, peu nombreux, recensés et revus à chaque changement.',
            'en'       => 'Dedicated administration accounts, few, listed and reviewed on every change.',
            'annex_a'  => ['A.8.2'],
            'modules'  => ['accounthygiene', 'accessreviews'],
        ],
        'CONFIG'      => [
            'category' => 'protection',
            'measures' => [
                'CONFIG.1', 'CONFIG.2', 'CONFIG.3', 'CONFIG.4', 'CONFIG.5', 'CONFIG.6', 'CONFIG.7', 'CONFIG.8',
            ],
            'fr'       => 'Configuration durcie et revue, flux non chiffrés et ports inutiles fermés, '
                . 'certificats conformes, ports USB des serveurs désactivés.',
            'en'       => 'Hardened and reviewed configuration, unencrypted flows and unused ports closed, '
                . 'compliant certificates, USB ports of servers disabled.',
            'annex_a'  => ['A.8.9', 'A.8.19', 'A.8.24'],
            'modules'  => [],
        ],
        'ADMIN'       => [
            'category' => 'protection',
            'measures' => ['ADMIN.1', 'ADMIN.2', 'ADMIN.3', 'ADMIN.4', 'ADMIN.5', 'ADMIN.6', 'ADMIN.7'],
            'fr'       => 'Administration depuis un réseau et des postes dédiés, flux cloisonnés et chiffrés.',
            'en'       => 'Administration from a dedicated network and workstations, segregated and encrypted '
                . 'flows.',
            'annex_a'  => ['A.8.2', 'A.8.22'],
            'modules'  => [],
        ],
        'INCIDENT'    => [
            'category' => 'defense',
            'measures' => [
                'INCIDENT.1', 'INCIDENT.2', 'INCIDENT.3', 'INCIDENT.4', 'INCIDENT.6', 'INCIDENT.7', 'INCIDENT.8',
            ],
            'fr'       => 'Traitement des incidents : signalement, qualification, contacts, point de contact '
                . 'public, réaction, analyse des causes et plan d\'action.',
            'en'       => 'Incident handling: reporting, qualification, contacts, public point of contact, '
                . 'response, root cause analysis and action plan.',
            'annex_a'  => ['A.5.5', 'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.6.8'],
            'modules'  => ['incidents', 'nonconformities'],
        ],
        'SUPERVISION' => [
            'category' => 'defense',
            'measures' => ['SUPERVISION.4', 'SUPERVISION.5', 'SUPERVISION.6', 'SUPERVISION.7'],
            'fr'       => 'Journaux de sécurité et d\'administration centralisés et conservés au moins trois mois.',
            'en'       => 'Security and administration logs centralised and kept for at least three months.',
            'annex_a'  => ['A.8.15', 'A.8.16'],
            'modules'  => [],
        ],
        'CONTINU'     => [
            'category' => 'resilience',
            'measures' => ['CONTINU.1', 'CONTINU.2', 'CONTINU.3', 'CONTINU.5', 'CONTINU.6'],
            'fr'       => 'Sauvegardes protégées et restaurations testées, durées d\'interruption et pertes '
                . 'admissibles, plan de reprise, haute disponibilité.',
            'en'       => 'Protected backups and tested restores, tolerable downtime and data loss, recovery '
                . 'plan, high availability.',
            'annex_a'  => ['A.8.13', 'A.8.14', 'A.5.29', 'A.5.30'],
            'modules'  => ['impactanalysis', 'exercises'],
        ],
    ];

    /**
     * Thèmes MonServiceSécurisé reliés à une mesure de l'Annexe A ISO 27001.
     *
     * @return list<string>
     */
    public static function themesForControl(string $annexACode): array
    {
        $codes = [];
        foreach (self::THEMES as $code => $theme) {
            if (in_array($annexACode, $theme['annex_a'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Nombre de mesures du référentiel (135 en version 2). */
    public static function measureCount(): int
    {
        return array_sum(array_map(static fn (array $theme): int => count($theme['measures']), self::THEMES));
    }
}

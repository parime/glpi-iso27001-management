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

use Glpi\Application\View\TemplateRenderer;

/**
 * Encadré « À quoi sert cette page ? » en tête de chaque écran du menu GRC & Conformité : ce
 * qu'on y trouve, ce qui est attendu de l'utilisateur, et la référence de la norme. Repliable ;
 * le choix est retenu par le navigateur, page par page.
 */
class PluginGrcmanagerPageHelp
{
    /**
     * @return array<string, array{purpose: string, expected: string, reference?: string}>
     */
    public static function texts(): array
    {
        return [
            'maturity' => [
                'purpose'  => __(
                    'Le score de maturité cyber (0 à 100) : une vue d\'ensemble de l\'avancement de votre système de '
                        . 'management de la sécurité, calculée à partir de vos données.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Présentez-le en revue de direction ; le détail du calcul montre quelles composantes '
                        . 'améliorer en priorité.',
                    'grcmanager'
                ),
            ],
            'risk' => [
                'purpose'   => __(
                    'Le registre des risques de sécurité de l\'information : chaque risque est évalué (probabilité '
                    . '× impact), relié aux actifs qu\'il menace et aux mesures qui le réduisent.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Identifiez les risques, évaluez-les, décidez d\'un traitement (réduire, accepter, transférer, '
                    . 'éviter) et fixez une date de revue. Le propriétaire est prévenu à l\'échéance.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.2 et 6.1.3 ; 8.2 et 8.3', 'grcmanager'),
            ],
            'riskheatmap' => [
                'purpose'  => __(
                    'La carte de chaleur des risques : leur répartition par probabilité et impact, pour voir d\'un '
                    . 'coup d\'œil où se concentrent les risques élevés.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Consultez-la en revue de direction ; cliquez sur une case pour ouvrir les risques '
                    . 'correspondants.',
                    'grcmanager'
                ),
            ],
            'riskcatalog' => [
                'purpose'  => __(
                    'Des risques types d\'infrastructure (service d\'administration exposé, réseau à plat, Wi-Fi '
                    . 'invités mal isolé...), prêts à être ajoutés au registre.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Cochez ceux qui vous concernent et importez-les, puis évaluez-les dans le registre. Rien '
                    . 'n\'est importé sans votre choix.',
                    'grcmanager'
                ),
            ],
            'supplierrisk' => [
                'purpose'   => __(
                    'Les risques liés à vos fournisseurs et prestataires : dépendance, accès à vos données, '
                    . 'sécurité de leurs services.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Créez un risque par fournisseur important, faites remplir le questionnaire de sécurité, '
                    . 'suivez ses contrats et l\'onglet « Veille sécurité ».',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 5.19 à 5.22', 'grcmanager'),
            ],
            'control' => [
                'purpose'   => __(
                    'La déclaration d\'applicabilité (SoA) : les 93 mesures de l\'Annexe A, chacune déclarée '
                    . 'applicable ou non, avec sa justification et son état de mise en œuvre.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Pour chaque mesure, indiquez si elle s\'applique, pourquoi, et où en est sa mise en œuvre. '
                    . 'Faites ensuite approuver la SoA.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.3 d)', 'grcmanager'),
            ],
            'soaapproval' => [
                'purpose'  => __(
                    'Les versions approuvées de la déclaration d\'applicabilité, avec l\'approbateur et la date.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Demandez l\'approbation après chaque évolution notable de la SoA ; l\'approbateur ne peut pas '
                    . 'être le demandeur.',
                    'grcmanager'
                ),
            ],
            'ismsrequirement' => [
                'purpose'   => __(
                    'Les exigences des articles 4 à 10 de la norme (contexte, leadership, planification, support, '
                    . 'fonctionnement, évaluation, amélioration) et la preuve de leur respect.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Pour chaque exigence, indiquez son état et joignez ou liez la preuve (document, revue, '
                    . 'procédure).',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, articles 4 à 10', 'grcmanager'),
            ],
            'complianceobligation' => [
                'purpose'   => __(
                    'Vos obligations légales, réglementaires et contractuelles en sécurité (RGPD, NIS2, clauses '
                    . 'clients...).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Recensez-les avec leur source et leur responsable, et fixez une date de revue pour vérifier '
                    . 'qu\'elles sont toujours respectées.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 4.2 ; Annexe A 5.31', 'grcmanager'),
            ],
            'audit' => [
                'purpose'   => __(
                    'Le programme d\'audits internes : périmètre, dates, auditeurs, rapport et constats.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Planifiez les audits de l\'année, saisissez le rapport et les constats ; chaque '
                    . 'non-conformité ouvre un plan d\'action.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.2', 'grcmanager'),
            ],
            'nonconformity' => [
                'purpose'   => __(
                    'Les non-conformités et observations, avec leurs actions correctives et préventives (CAPA).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Analysez la cause, décrivez l\'action corrective, désignez un responsable et une échéance, '
                    . 'puis vérifiez l\'efficacité avant de clôturer.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 10.2', 'grcmanager'),
            ],
            'training' => [
                'purpose'   => __(
                    'Les formations et actions de sensibilisation à la sécurité, avec les participants et leurs '
                    . 'résultats.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enregistrez chaque session, ses participants et sa date de renouvellement ; un rappel est '
                    . 'envoyé à l\'échéance.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 7.2 et 7.3', 'grcmanager'),
            ],
            'managementreview' => [
                'purpose'   => __(
                    'Les revues de direction : le bilan périodique du système de management de la sécurité par la '
                    . 'direction.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Au moins une fois par an, préparez la revue (indicateurs, audits, incidents, risques), notez '
                    . 'les décisions et les actions qui en découlent.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 9.3', 'grcmanager'),
            ],
            'objective' => [
                'purpose'   => __(
                    'Les objectifs de sécurité et leurs indicateurs mesurés dans le temps.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Définissez des objectifs mesurables, saisissez les mesures à chaque période et suivez '
                    . 'l\'atteinte de la cible.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.2 et 9.1', 'grcmanager'),
            ],
            'accessreview' => [
                'purpose'   => __(
                    'Les campagnes de revue des droits d\'accès : chaque responsable confirme ou retire les droits '
                    . 'de son équipe.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Lancez une campagne, les responsables se prononcent ligne par ligne ; un retrait de droit '
                    . 'ouvre un ticket, il n\'est jamais appliqué automatiquement.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 5.18', 'grcmanager'),
            ],
            'accounthygiene' => [
                'purpose'  => __(
                    'Les comptes à vérifier : comptes inactifs, absents de l\'annuaire, en double ou jamais '
                    . 'connectés.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Passez la liste en revue et ouvrez un ticket pour chaque compte à désactiver ; rien n\'est '
                    . 'désactivé automatiquement.',
                    'grcmanager'
                ),
            ],
            'periodicreview' => [
                'purpose'  => __(
                    'Les revues périodiques (sauvegardes, journaux, droits, plan de continuité...) programmées '
                    . 'comme des tickets récurrents.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Activez les revues utiles et leur fréquence ; un ticket est créé à chaque échéance pour la '
                    . 'personne en charge.',
                    'grcmanager'
                ),
            ],
            'networkflow' => [
                'purpose'   => __(
                    'La matrice des flux réseau autorisés : qui parle à qui, sur quel port, et pourquoi.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Décrivez chaque flux avec sa justification et son responsable, ou importez l\'export de votre '
                    . 'pare-feu, puis revoyez la matrice régulièrement.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 8.20 à 8.22', 'grcmanager'),
            ],
            'compliancecalendar' => [
                'purpose'  => __(
                    'Le calendrier de conformité : toutes les échéances (revues, audits, renouvellements, '
                    . 'obligations) au même endroit, aussi visibles dans le planning GLPI.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Consultez-le pour anticiper les échéances ; abonnez votre agenda avec le lien iCal.',
                    'grcmanager'
                ),
            ],
            'complianceevent' => [
                'purpose'  => __(
                    'Les événements de conformité ponctuels à ajouter au calendrier (contrôle d\'un régulateur, '
                    . 'exercice de crise, échéance contractuelle...).',
                    'grcmanager'
                ),
                'expected' => __(
                    'Ajoutez l\'événement avec sa date et son responsable ; il apparaît dans le calendrier de '
                    . 'conformité.',
                    'grcmanager'
                ),
            ],
            'policy' => [
                'purpose'   => __(
                    'La bibliothèque des politiques de sécurité, versionnées, avec leur date de revue et leur taux '
                    . 'de lecture.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Déposez chaque politique, faites-la valider, diffusez-la et revoyez-la à l\'échéance.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 5.2 ; Annexe A 5.1', 'grcmanager'),
            ],
            'evidence' => [
                'purpose'  => __(
                    'Les preuves datées (rapports, attestations, certificats) et leur validité.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Indiquez jusqu\'à quand chaque preuve est valable ; un ticket de renouvellement est ouvert '
                    . 'quand elle expire.',
                    'grcmanager'
                ),
            ],
            'coveragerule' => [
                'purpose'  => __(
                    'Les règles de couverture : quels actifs doivent avoir quel document (par exemple, chaque '
                    . 'serveur doit avoir sa fiche d\'exploitation).',
                    'grcmanager'
                ),
                'expected' => __(
                    'Créez une règle par exigence ; le résultat liste les actifs couverts et ceux à compléter.',
                    'grcmanager'
                ),
            ],
            'waiver' => [
                'purpose'   => __(
                    'Les dérogations de sécurité : les exceptions acceptées à une politique ou à une mesure, '
                    . 'limitées dans le temps et compensées.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Justifiez chaque dérogation, décrivez les mesures compensatoires, faites-la valider et fixez '
                    . 'sa date de fin ; elle est réévaluée à l\'échéance.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, 6.1.3 ; Annexe A 5.36', 'grcmanager'),
            ],
            'impactanalysis' => [
                'purpose'   => __(
                    'Les points de défaillance uniques : les éléments critiques qui ne dépendent que d\'une seule '
                    . 'source, d\'après l\'analyse d\'impact de GLPI.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Créez le risque correspondant ou ajoutez une redondance ; exportez la liste pour votre plan '
                    . 'de continuité.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 5.29, 5.30 et 8.14', 'grcmanager'),
            ],
            'causalgraph' => [
                'purpose'  => __(
                    'L\'effet domino : comment un incident ou un risque peut en entraîner d\'autres.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Reliez les éléments depuis l\'onglet « Effet domino » d\'un risque ou d\'un incident ; cette '
                    . 'page montre l\'ensemble.',
                    'grcmanager'
                ),
            ],
            'securityincident' => [
                'purpose'   => __(
                    'Les incidents de sécurité : qualification, actifs touchés, CVE, coûts, notifications '
                    . 'réglementaires (CNIL, NIS2).',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Déclarez l\'incident, qualifiez-le, suivez les actions et respectez les délais de '
                    . 'notification rappelés sur la fiche.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 5.24 à 5.28', 'grcmanager'),
            ],
            'parkvulnerability' => [
                'purpose'  => __(
                    'Les vulnérabilités connues (CVE) des logiciels installés sur votre parc, avec leur '
                    . 'exploitation réelle (KEV, EPSS) et les avis du CERT-FR.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Traitez d\'abord les vulnérabilités exploitées et prioritaires ; ouvrez un ticket de mise à '
                    . 'jour par logiciel.',
                    'grcmanager'
                ),
                'reference' => __('ISO 27001, Annexe A 8.8', 'grcmanager'),
            ],
            'softwarewatch' => [
                'purpose'  => __(
                    'Les logiciels du parc à rapprocher d\'une référence CPE pour que leurs vulnérabilités soient '
                    . 'suivies.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Commencez par les plus exposés (navigateurs, accès distant, VPN) : cherchez le CPE, '
                    . 'confirmez-le, ou ignorez un logiciel sans enjeu.',
                    'grcmanager'
                ),
            ],
            'canonicalproduct' => [
                'purpose'  => __(
                    'Le catalogue de corrélation : les produits de référence, leurs CPE et les noms sous lesquels '
                    . 'ils apparaissent dans l\'inventaire.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Il se remplit depuis « Logiciels à surveiller » ; corrigez ici un alias ou un CPE si besoin.',
                    'grcmanager'
                ),
            ],
            'referentiels' => [
                'purpose'  => __(
                    'Les correspondances entre l\'Annexe A et d\'autres référentiels, pour réutiliser votre '
                    . 'travail sur plusieurs normes.',
                    'grcmanager'
                ),
                'expected' => __(
                    'Consultez les correspondances avant un audit portant sur un autre référentiel.',
                    'grcmanager'
                ),
            ],
        ];
    }

    public static function show(string $page): void
    {
        $text = self::texts()[$page] ?? null;
        if ($text === null) {
            return;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/_page_help.html.twig', ['page' => $page] + $text);
    }
}

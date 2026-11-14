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

namespace GlpiPlugin\Grcmanager\Services\Notification;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessReminder;
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTestReminder;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\IncidentAccessWatch;
use GlpiPlugin\Grcmanager\KriAlert;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\NotificationTargetAccessReview;
use GlpiPlugin\Grcmanager\NotificationTargetBreachExposure;
use GlpiPlugin\Grcmanager\NotificationTargetBusinessProcess;
use GlpiPlugin\Grcmanager\NotificationTargetComplianceEvent;
use GlpiPlugin\Grcmanager\NotificationTargetControl;
use GlpiPlugin\Grcmanager\NotificationTargetEvidence;
use GlpiPlugin\Grcmanager\NotificationTargetIncidentAccessWatch;
use GlpiPlugin\Grcmanager\NotificationTargetRiskIndicator;
use GlpiPlugin\Grcmanager\NotificationTargetSoaVersion;
use GlpiPlugin\Grcmanager\NotificationTargetTraining;
use GlpiPlugin\Grcmanager\NotificationTargetVulnerabilityDigest;
use GlpiPlugin\Grcmanager\NotificationTargetWaiver;
use GlpiPlugin\Grcmanager\NotificationTargetPolicy;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;
use GlpiPlugin\Grcmanager\SoaVersion;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\Training;
use GlpiPlugin\Grcmanager\VulnerabilityDigest;
use GlpiPlugin\Grcmanager\Waiver;
use Notification;

/**
 * Issue #582 : modèles de notification du plugin, multilingues. Chaque modèle a une traduction par
 * défaut en anglais (langue vide, comme les modèles de GLPI) et une traduction par langue du plugin
 * (LANGUAGES), écrites à l'installation à partir des catalogues locales/*.mo : un destinataire
 * italien reçoit le modèle italien, une langue sans traduction reçoit l'anglais.
 *
 * Les textes sont des msgid anglais passés au traducteur `$t` (MoCatalog::translator()). Jusqu'à
 * la version 2.x, les modèles étaient créés en français dans la traduction par défaut :
 * LEGACY_FR redonne ces textes d'origine, mot pour mot, pour reconnaître à la mise à jour une
 * traduction que l'administrateur n'a pas modifiée (Installer::migrateNotificationTemplateLanguages()).
 */
final class NotificationTemplateDefaults
{
    /**
     * Langues des traductions ajoutées à chaque modèle, en plus de la traduction par défaut
     * (anglais). Les variantes du français gardent un modèle français, comme avant l'issue #582.
     */
    public const LANGUAGES = ['fr_FR', 'fr_BE', 'fr_CA', 'it_IT', 'de_DE', 'es_ES', 'pl_PL', 'pt_BR'];

    public const NAME_PREFIX = 'GRC Manager - ';

    /**
     * Textes français des modèles créés avant l'issue #582, tels qu'ils étaient écrits (y compris
     * « Voir le obligation »). Ne pas corriger : sert seulement à reconnaître un modèle d'origine.
     *
     * @var array<string, string>
     */
    public const LEGACY_FR = [
        '%1$s: %2$s' => '%1$s : %2$s',
        'Category' => 'Catégorie',
        'Risk level' => 'Niveau de risque',
        'Type' => 'Type',
        'Compliance status' => 'Statut de conformité',
        'Review date' => 'Date de revue',
        'View the risk' => 'Voir le risque',
        'View the supplier risk' => 'Voir le risque fournisseur',
        'View the obligation' => 'Voir le obligation',
        'Audit' => 'Audit',
        'Severity' => 'Sévérité',
        'Due date' => 'Échéance',
        'View the non-conformity' => 'Voir la non-conformité',
        'Supplier risk' => 'Risque fournisseur',
        'View the supplier risk (Security watch tab)' => 'Voir le risque fournisseur (onglet Veille sécurité)',
        'Item' => 'Élément',
        'Valid until' => 'Valide jusqu\'au',
        'Renew the document (new certificate, report or attestation), then update its validity date. A '
            . 'renewal ticket will be opened if the evidence expires.' => 'Renouvelez le document (nouveau '
            . 'certificat, rapport ou attestation) puis mettez à jour sa date de validité. Un ticket de '
            . 'renouvellement sera ouvert si la preuve expire.',
        'View the item' => 'Voir l\'élément',
        'Begin' => 'Début',
        'Scope' => 'Périmètre',
        'Prepare the event: invitations, expected documents, access for the service provider. Nothing is '
            . 'changed automatically.' => 'Préparez l\'événement : convocations, documents attendus, accès du '
            . 'prestataire. Rien n\'est modifié automatiquement.',
        'View the event' => 'Voir l\'événement',
        'Next test' => 'Prochain test',
        'Last test' => 'Dernier test',
        'Result' => 'Résultat',
        'Test plan owner' => 'Responsable du plan de test',
        'Implementation status' => 'État de mise en œuvre',
        'Run the test and record its result in the "Effectiveness tests" tab of the control. An overdue test '
            . 'removes the "Verified" state of the control in the SoA.' => 'Réalisez le test et enregistrez son '
            . 'résultat dans l\'onglet « Tests d\'efficacité » de la mesure. Un test en retard retire l\'état '
            . '« Vérifié » de la mesure dans la SoA.',
        'View the control' => 'Voir la mesure',
        'Status' => 'Statut',
        'Previous status' => 'Statut précédent',
        'Last value' => 'Dernière valeur',
        'Recorded on' => 'Relevée le',
        'Orange threshold' => 'Seuil orange',
        'Red threshold' => 'Seuil rouge',
        'Source' => 'Source',
        'Entity' => 'Entité',
        'Owner' => 'Responsable',
        'Status: %1$s (before: %2$s)' => 'Statut : %1$s (avant : %2$s)',
        'Last value: %1$s, recorded on %2$s' => 'Dernière valeur : %1$s, relevée le %2$s',
        'Thresholds: orange %1$s, red %2$s' => 'Seuils : orange %1$s, rouge %2$s',
        'View the indicator' => 'Voir l\'indicateur',
        'Analyse the cause, decide on a treatment and, if needed, create the ticket proposed on the indicator '
            . 'form.' => 'Analysez la cause, décidez d\'un traitement et, si besoin, créez le ticket proposé sur la '
            . 'fiche de l\'indicateur.',
        'The indicator is back below its thresholds. Check whether the related ticket, if any, can be closed.'
            => 'L\'indicateur est revenu sous ses seuils. Vérifiez si le ticket éventuel peut être clos.',
        'Process' => 'Processus',
        'To review on' => 'À revoir le',
        'Last BIA' => 'Dernière BIA',
        'Test due on' => 'Test dû le',
        'Measured time' => 'Temps mesuré',
        'RTO' => 'RTO',
        'Criticality' => 'Criticité',
        'Review with the owner the impacts of an interruption over time, the MTPD, the RTO and the RPO, then '
            . 'record the BIA in the "Business impact analysis (BIA)" tab of the process.' => 'Revoyez avec le '
            . 'responsable les impacts d\'une interruption dans le temps, la DMIA, le RTO et le RPO, puis '
            . 'enregistrez la BIA dans l\'onglet « Analyse d\'impact (BIA) » du processus.',
        'View the BIA' => 'Voir la BIA',
        'Plan a test or a crisis exercise, then record the measured recovery time in the "Recovery strategy" '
            . 'tab of the process.' => 'Planifiez un test ou un exercice de crise, puis enregistrez le temps de '
            . 'reprise mesuré dans l\'onglet « Stratégie de reprise » du processus.',
        'View the recovery strategy' => 'Voir la stratégie de reprise',
        'End date' => 'Date de fin',
        'Reassess the waiver: extend it with a new end date if the exception is still needed, or close it once '
            . 'the situation has been regularised. A reassessment ticket will be opened on the due date.'
            => 'Réévaluez la dérogation : prolongez-la avec une nouvelle date de fin si l\'exception est '
            . 'toujours nécessaire, ou close-la une fois la situation régularisée. Un ticket de réévaluation '
            . 'sera ouvert à l\'échéance.',
        'View the waiver' => 'Voir la dérogation',
        'Format' => 'Format',
        'Renewal (months)' => 'Renouvellement (mois)',
        'View the training' => 'Voir la formation',
        'Nothing was changed automatically.' => 'Rien n\'a été modifié automatiquement.',
        'Audit log' => 'Journal d\'audit',
        'Open the audit log' => 'Ouvrir le journal d\'audit',
        'Work addresses found in known data breaches.' => 'Adresses professionnelles trouvées dans des fuites '
            . 'de données connues.',
        'Details in the register (restricted access)' => 'Détail dans le registre (accès restreint)',
        'Details in the register (restricted access).' => 'Détail dans le registre (accès restreint).',
        'Open the register' => 'Ouvrir le registre',
        'Actively exploited (CISA KEV)' => 'Exploitées activement (CISA KEV)',
        'Probably exploited (EPSS)' => 'Probablement exploitées (EPSS)',
        'Critical (CVSS 9 or more)' => 'Critiques (CVSS 9 ou plus)',
        'High (CVSS below 9)' => 'Élevées (CVSS inférieur à 9)',
        'Exposed machines' => 'Machines exposées',
        'New since the last report' => 'Nouvelles depuis le dernier rapport',
        'Handle the exploited vulnerabilities first: update the software or apply the vendor\'s workaround. '
            . 'The full list, the affected machines and ticket creation are in GLPI.' => 'Traitez d\'abord les '
            . 'vulnérabilités exploitées : mettez à jour le logiciel ou appliquez la mesure de contournement de '
            . 'l\'éditeur. La liste complète, les machines concernées et la création de tickets sont dans GLPI.',
        '- %1$s: %2$s (%3$s), %4$s, %5$s, %6$s machine(s) %7$s'
            => '- %1$s : %2$s (%3$s), %4$s, %5$s, %6$s machine(s) %7$s',
        'View the asset vulnerabilities' => 'Voir les vulnérabilités du parc',
        'Asset vulnerability report' => 'Rapport des vulnérabilités du parc',
        'Software' => 'Logiciel',
        'Machines' => 'Machines',
        'Version' => 'Version',
        'Next review' => 'Prochaine revue',
        'View the policy' => 'Voir la politique',
        'Action' => 'Action',
        'version %s' => 'version %s',
        '%1$s asks you to approve version %2$s of the Statement of Applicability (%3$s).' => '%1$s vous '
            . 'demande d\'approuver la version %2$s de la Déclaration d\'Applicabilité (%3$s).',
        'Read the frozen version and answer' => 'Lire la version figée et répondre',
        'Access review to complete' => 'Revue des accès à réaliser',
        '%1$s asks you to review the access rights of your team\'s accounts before %2$s (campaign "%3$s").'
            => '%1$s vous demande de revoir les accès des comptes de votre équipe avant le %2$s (campagne '
            . '« %3$s »).',
        'Complete my access review' => 'Faire ma revue des accès',
        'Reminder: access review %s' => 'Relance : revue des accès %s',
        'The %1$s deadline has passed and accounts of your team are still waiting for your decision '
            . '(campaign "%2$s").' => 'L\'échéance du %1$s est dépassée et des comptes de votre équipe '
            . 'attendent encore votre décision (campagne « %2$s »).',
        'Finish my access review' => 'Terminer ma revue des accès',
    ];

    /**
     * Modèles du plugin, par clé : type d'objet et événement de la notification, nom et commentaire
     * (msgid anglais, sans le préfixe « GRC Manager - »), cibles par défaut, et nom/commentaire
     * français d'origine (reconnaissance à la mise à jour). Le modèle commun des incidents de
     * sécurité (« Security incident », balises seules, sans texte) n'en fait pas partie.
     *
     * @return array<string, array{itemtype: class-string, event: string, name: string, comment: string,
     *     notification_comment: string, targets: list<int>, legacy_name: string, legacy_comment: string,
     *     legacy_notification_comment: string}>
     */
    public static function definitions(): array
    {
        $sentByTask = 'Sent by the GRC Manager automatic task when ';
        $legacyByTask = 'Notification envoyée par la tâche automatique GRC Manager ';
        $owner = [Notification::ITEM_USER];
        $definitions = [
            'risk_review' => [
                Risk::class, Risk::REVIEW_DUE_EVENT, 'Risk review due',
                $sentByTask . 'a risk reaches or passes its review date.', $owner,
                'Revue de risque à échéance',
                $legacyByTask . 'lorsqu\'un risque atteint ou dépasse sa date de revue.',
            ],
            'supplierrisk_review' => [
                SupplierRisk::class, 'review_due', 'Supplier risk review due',
                $sentByTask . 'a supplier risk reaches or passes its review date.', $owner,
                'Revue de risque fournisseur à échéance',
                $legacyByTask . 'lorsqu\'un risque fournisseur atteint ou dépasse sa date de revue.',
            ],
            'obligation_review' => [
                ComplianceObligation::class, 'review_due', 'Obligation review due',
                $sentByTask . 'an obligation reaches or passes its review date.', $owner,
                'Revue d\'obligation à échéance',
                $legacyByTask . 'lorsqu\'un obligation atteint ou dépasse sa date de revue.',
            ],
            'capa_overdue' => [
                Nonconformity::class, 'capa_overdue', 'Overdue corrective/preventive action',
                $sentByTask . 'a non-conformity passes its due date without being closed or verified.', $owner,
                'Action corrective/préventive en retard',
                $legacyByTask . 'lorsqu\'une non-conformité dépasse son échéance sans être clôturée ni vérifiée.',
            ],
            'security_watch' => [
                SupplierRisk::class, 'security_watch', 'Security notice about a supplier',
                'Sent by the asset vulnerability task when a new CERT-FR notice mentions the supplier of a risk '
                    . 'in the register.',
                $owner,
                'Avis de sécurité concernant un fournisseur',
                'Notification envoyée par la tâche des vulnérabilités du parc lorsqu\'un nouvel avis du CERT-FR '
                    . 'cite le fournisseur d\'un risque du registre.',
            ],
            'evidence_expiring' => [
                Evidence::class, 'evidence_expiring', 'Evidence to renew',
                $sentByTask . 'an evidence document reaches the end of its validity.',
                [NotificationTargetEvidence::TARGET_RESPONSIBLE],
                'Preuve à renouveler',
                $legacyByTask . 'lorsqu\'un document de preuve arrive à la fin de sa validité.',
            ],
            'complianceevent_reminder' => [
                ComplianceEvent::class, 'complianceevent_reminder', 'Upcoming compliance event',
                'Sent by the GRC Manager automatic task 15 days before an event of the compliance calendar '
                    . '(audit, pentest, certification...).',
                [NotificationTargetComplianceEvent::TARGET_RESPONSIBLE],
                'Événement de conformité à venir',
                $legacyByTask . '15 jours avant un événement du calendrier de conformité (audit, pentest, '
                    . 'certification...).',
            ],
            'controltest_reminder' => [
                Control::class, ControlTestReminder::EVENT, 'Effectiveness test to perform',
                $sentByTask . 'an effectiveness test of an Annex A control is overdue or due within 30 days.',
                [NotificationTargetControl::TARGET_TESTER, NotificationTargetControl::TARGET_CISO_GROUP],
                'Test d\'efficacité à réaliser',
                $legacyByTask . 'lorsqu\'un test d\'efficacité d\'une mesure de l\'Annexe A est en retard ou à '
                    . 'réaliser dans les 30 jours.',
            ],
            'kri_alert' => [
                RiskIndicator::class, KriAlert::EVENT_ALERT, 'Risk indicator in alert',
                'Sent by GRC Manager when a key risk indicator (KRI) turns red, or orange depending on the '
                    . 'setting, once per change of state.',
                [NotificationTargetRiskIndicator::TARGET_OWNER, NotificationTargetRiskIndicator::TARGET_CISO_GROUP],
                'Indicateur de risque en alerte',
                'Notification envoyée par GRC Manager lorsqu\'un indicateur de risque (KRI) passe au rouge, ou à '
                    . 'l\'orange selon le réglage, une fois par changement d\'état.',
            ],
            'kri_cleared' => [
                RiskIndicator::class, KriAlert::EVENT_CLEARED, 'Risk indicator back to green',
                'Sent by GRC Manager when a key risk indicator (KRI) in alert goes back to green.',
                [NotificationTargetRiskIndicator::TARGET_OWNER, NotificationTargetRiskIndicator::TARGET_CISO_GROUP],
                'Indicateur de risque revenu au vert',
                'Notification envoyée par GRC Manager lorsqu\'un indicateur de risque (KRI) en alerte revient au '
                    . 'vert.',
            ],
            'bia_review' => [
                BusinessProcess::class, BusinessProcessReminder::EVENT_BIA, 'Business impact analysis (BIA) to review',
                $sentByTask . 'the business impact analysis (BIA) of a business process must be reviewed within '
                    . '30 days or is overdue (date of the last BIA plus the frequency of the periodic review).',
                [NotificationTargetBusinessProcess::TARGET_OWNER, NotificationTargetBusinessProcess::TARGET_CISO_GROUP],
                'Analyse d\'impact (BIA) à revoir',
                $legacyByTask . 'lorsque l\'analyse d\'impact sur l\'activité (BIA) d\'un processus métier doit '
                    . 'être revue dans les 30 jours ou est en retard (date de la dernière BIA plus la fréquence de '
                    . 'la revue périodique).',
            ],
            'recovery_test' => [
                BusinessProcess::class, BusinessProcessReminder::EVENT_RECOVERY, 'Recovery test to redo',
                $sentByTask . 'the next recovery test of a business process is due within 30 days or overdue '
                    . '(date of the last test plus the frequency of the periodic review of the BCP / DRP tests).',
                [NotificationTargetBusinessProcess::TARGET_OWNER, NotificationTargetBusinessProcess::TARGET_CISO_GROUP],
                'Test de reprise à refaire',
                $legacyByTask . 'lorsque le prochain test de reprise d\'un processus métier est dû dans les 30 '
                    . 'jours ou en retard (date du dernier test plus la fréquence de la revue périodique des tests '
                    . 'du PCA / PRA).',
            ],
            'waiver_expiring' => [
                Waiver::class, 'waiver_expiring', 'Waiver expiring soon',
                'Sent by the GRC Manager automatic task 15 days before the end of a security waiver.',
                [NotificationTargetWaiver::TARGET_RESPONSIBLE],
                'Dérogation bientôt échue',
                $legacyByTask . '15 jours avant la fin d\'une dérogation de sécurité.',
            ],
            'training_renewal' => [
                Training::class, 'training_renewal_due', 'Overdue training renewal',
                $sentByTask . 'a participant is overdue for the renewal of a training.',
                [NotificationTargetTraining::TARGET_OVERDUE_PARTICIPANTS],
                'Renouvellement de formation en retard',
                $legacyByTask . 'lorsqu\'un participant est en retard de renouvellement pour une formation.',
            ],
            'accesswatch_changed' => [
                IncidentAccessWatch::class, IncidentAccessWatch::ACCESS_CHANGED_EVENT,
                'Security incident access changed',
                'Sent by the "accesswatch" task or when the module is disabled.',
                [NotificationTargetIncidentAccessWatch::TARGET_CISO_GROUP, Notification::GLOBAL_ADMINISTRATOR],
                'Accès aux incidents de sécurité modifiés',
                'Issue #371 : envoyée par la tâche « accesswatch » ou à la désactivation du module.',
                'Access to the security incident module changed',
                'Accès au module des incidents de sécurité modifiés',
            ],
            'accesswatch_disabled' => [
                IncidentAccessWatch::class, IncidentAccessWatch::MODULE_DISABLED_EVENT,
                'Security incident module disabled',
                'Sent by the "accesswatch" task or when the module is disabled.',
                [NotificationTargetIncidentAccessWatch::TARGET_CISO_GROUP, Notification::GLOBAL_ADMINISTRATOR],
                'Module des incidents de sécurité désactivé',
                'Issue #371 : envoyée par la tâche « accesswatch » ou à la désactivation du module.',
                'Security incident module disabled',
                'Module des incidents de sécurité désactivé',
            ],
            'breach_exposures' => [
                BreachExposure::class, BreachExposure::NEW_EXPOSURES_EVENT, 'New data breach exposures',
                'Sent by the "breachmonitor" automatic task (Have I Been Pwned).',
                [NotificationTargetBreachExposure::TARGET_CISO_GROUP, Notification::GLOBAL_ADMINISTRATOR],
                'Nouvelles expositions à des fuites de données',
                'Envoyée par la tâche automatique « breachmonitor » (Have I Been Pwned).',
            ],
            'vulnerability_digest' => [
                VulnerabilityDigest::class, VulnerabilityDigest::EVENT, 'Asset vulnerability report',
                'Sent by the "vulnerabilitydigest" automatic task at the frequency chosen in the GRC Manager '
                    . 'configuration (exploited, probably exploited, critical and high vulnerabilities of the '
                    . 'assets).',
                [NotificationTargetVulnerabilityDigest::TARGET_CISO_GROUP],
                'Rapport des vulnérabilités du parc',
                'Envoyée par la tâche automatique « vulnerabilitydigest » selon la fréquence choisie dans la '
                    . 'configuration de GRC Manager (vulnérabilités exploitées, probablement exploitées, '
                    . 'critiques et élevées du parc).',
            ],
            'policy_review' => [
                Policy::class, 'policy_review_due', 'Security policy review due',
                $sentByTask . 'a security policy reaches or passes its next review date.', $owner,
                'Revue de politique de sécurité à échéance',
                $legacyByTask . 'lorsqu\'une politique de sécurité atteint ou dépasse sa prochaine date de revue.',
            ],
            'treatmentaction_overdue' => [
                RiskTreatmentAction::class, 'treatment_action_overdue', 'Overdue risk treatment action',
                $sentByTask . 'an action of a risk treatment plan passes its due date without being completed.',
                $owner,
                'Action de traitement de risque en retard',
                $legacyByTask . 'lorsqu\'une action du plan de traitement d\'un risque dépasse son échéance sans '
                    . 'être réalisée.',
            ],
            'soa_approval' => [
                SoaVersion::class, 'soa_approval_request', 'SoA approval requested',
                'Sent to the designated approvers when a version of the Statement of Applicability is frozen '
                    . 'and submitted for approval.',
                [NotificationTargetSoaVersion::APPROVERS_TARGET],
                'Approbation de la SoA demandée',
                'Envoyée aux approbateurs désignés quand une version de la Déclaration d\'Applicabilité est '
                    . 'figée et soumise à approbation.',
            ],
            'accessreview_request' => [
                AccessReview::class, 'access_review_request', 'Access review to complete', '',
                [NotificationTargetAccessReview::REVIEWERS_TARGET],
                'Revue des accès à réaliser', '',
            ],
            'accessreview_reminder' => [
                AccessReview::class, 'access_review_reminder', 'Access review reminder', '',
                [NotificationTargetAccessReview::REVIEWERS_TARGET],
                'Relance de la revue des accès', '',
            ],
            // Issue #535 : prise de connaissance d'une politique par son public, et relance.
            'policy_acknowledgement' => [
                Policy::class, PolicyAcknowledgement::REQUEST_EVENT, 'Security policy to acknowledge',
                'Sent to the audience of a security policy when an acknowledgement campaign is launched.',
                [NotificationTargetPolicy::TARGET_AUDIENCE],
                'Politique de sécurité à lire',
                'Envoyée au public d\'une politique de sécurité au lancement d\'une campagne de prise de connaissance.',
            ],
            'policy_acknowledgement_reminder' => [
                Policy::class, PolicyAcknowledgement::REMINDER_EVENT, 'Security policy acknowledgement reminder',
                $sentByTask . 'the acknowledgement deadline of a security policy has passed (at most once a week).',
                [NotificationTargetPolicy::TARGET_AUDIENCE],
                'Relance de lecture d\'une politique de sécurité',
                $legacyByTask . 'lorsque l\'échéance de prise de connaissance d\'une politique est dépassée.',
            ],
        ];

        $result = [];
        foreach ($definitions as $key => $row) {
            $result[$key] = [
                'itemtype'                    => $row[0],
                'event'                       => $row[1],
                'name'                        => $row[2],
                'comment'                     => $row[3],
                'targets'                     => $row[4],
                'legacy_name'                 => self::NAME_PREFIX . $row[5],
                'legacy_comment'              => $row[6],
                'notification_comment'        => $row[7] ?? '',
                'legacy_notification_comment' => $row[8] ?? '',
            ];
        }

        return $result;
    }

    /** Traducteur des textes français d'origine (LEGACY_FR), msgid anglais sinon. */
    public static function legacyTranslator(): \Closure
    {
        return static fn (string $msgid): string => self::LEGACY_FR[$msgid] ?? $msgid;
    }

    /**
     * Objet, texte et HTML d'un modèle dans la langue du traducteur.
     *
     * @param callable(string): string $t
     * @return array{subject: string, content_text: string, content_html: string}
     */
    public static function build(string $key, callable $t): array
    {
        $line = static fn (string $label, string $value): string => sprintf($t('%1$s: %2$s'), $t($label), $value);
        $head = static fn (string $left, string $right): string => sprintf($t('%1$s: %2$s'), $left, $right);

        switch ($key) {
            case 'risk_review':
            case 'supplierrisk_review':
            case 'obligation_review':
                $prefix = ['risk_review' => 'risk', 'supplierrisk_review' => 'supplierrisk',
                    'obligation_review' => 'complianceobligation'][$key];
                $details = $key === 'obligation_review'
                    ? ['type' => 'Type', 'status' => 'Compliance status']
                    : ['category' => 'Category', 'risklevel' => 'Risk level'];
                $view = ['risk_review' => 'View the risk', 'supplierrisk_review' => 'View the supplier risk',
                    'obligation_review' => 'View the obligation'][$key];
                $title = $head("##{$prefix}.action##", "##{$prefix}.title##");
                $lines = [];
                foreach ($details as $suffix => $label) {
                    $lines[] = $line($label, "##{$prefix}.{$suffix}##");
                }
                $lines[] = $line('Review date', "##{$prefix}.reviewdate##");

                return self::simple($title, $title, $lines, [], "##{$prefix}.url##", $t($view), $head);

            case 'capa_overdue':
                $title = $head('##nc.action##', '##nc.title##');

                return self::simple($title, $title, [
                    $line('Audit', '##nc.audit##'),
                    $line('Severity', '##nc.severity##'),
                    $line('Due date', '##nc.duedate##'),
                ], [], '##nc.url##', $t('View the non-conformity'), $head);

            case 'security_watch':
                $title = $head('##supplierrisk.action##', '##supplierrisk.supplier##');

                // Texte seul : la liste des avis est déjà une liste de lignes.
                return [
                    'subject'      => $title,
                    'content_text' => $title . "\n\n##supplierrisk.notices##\n\n"
                        . $line('Supplier risk', '##supplierrisk.title##') . "\n"
                        . $line('View the supplier risk (Security watch tab)', '##supplierrisk.url##'),
                    'content_html' => '',
                ];

            case 'evidence_expiring':
                $title = $head('##evidence.action##', '##evidence.document##');

                return self::simple($title, $title, [
                    $line('Item', '##evidence.item##'),
                    $line('Valid until', '##evidence.validuntil##'),
                ], [
                    $t('Renew the document (new certificate, report or attestation), then update its validity '
                        . 'date. A renewal ticket will be opened if the evidence expires.'),
                ], '##evidence.url##', $t('View the item'), $head);

            case 'complianceevent_reminder':
                $title = $head('##complianceevent.action##', '##complianceevent.name##');

                return self::plain($title, $title . "\n\n"
                    . $line('Type', '##complianceevent.type##') . "\n"
                    . $line('Begin', '##complianceevent.begin##') . "\n"
                    . $line('Scope', '##complianceevent.scope##') . "\n\n"
                    . $t('Prepare the event: invitations, expected documents, access for the service provider. '
                        . 'Nothing is changed automatically.') . "\n\n"
                    . $line('View the event', '##complianceevent.url##'));

            case 'waiver_expiring':
                $title = $head('##waiver.action##', '##waiver.name##');

                return self::plain($title, $title . "\n\n"
                    . $line('End date', '##waiver.dateend##') . "\n\n"
                    . $t('Reassess the waiver: extend it with a new end date if the exception is still needed, or '
                        . 'close it once the situation has been regularised. A reassessment ticket will be opened on '
                        . 'the due date.') . "\n\n"
                    . $line('View the waiver', '##waiver.url##'));

            case 'controltest_reminder':
                $advice = $t('Run the test and record its result in the "Effectiveness tests" tab of the control. An '
                    . 'overdue test removes the "Verified" state of the control in the SoA.');
                $title = $head('##controltest.duestatus##', '##controltest.control##');
                $text = $title . "\n\n"
                    . $line('Next test', '##controltest.nextdate##') . "\n"
                    . $line('Last test', '##controltest.lastdate## (##controltest.lastresult##)') . "\n"
                    . $line('Test plan owner', '##controltest.tester##') . "\n"
                    . $line('Implementation status', '##controltest.status##') . "\n"
                    . "##IFcontroltest.withdrawn##\n##controltest.withdrawn##\n##ENDIFcontroltest.withdrawn##\n"
                    . $advice . "\n\n"
                    . $line('View the control', '##controltest.url##');

                return [
                    'subject'      => $title,
                    'content_text' => $text,
                    'content_html' => NotificationHtml::renderBranded(
                        '##controltest.brandcolor##',
                        $title,
                        '##controltest.action##',
                        self::rows($t, [
                            'Next test'             => '##controltest.nextdate##',
                            'Last test'             => '##controltest.lastdate##',
                            'Result'                => '##controltest.lastresult##',
                            'Test plan owner'       => '##controltest.tester##',
                            'Implementation status' => '##controltest.status##',
                        ]),
                        ['tag' => '##controltest.withdrawn##', 'if_tag' => 'controltest.withdrawn'],
                        [$advice],
                        ['url' => '##controltest.url##', 'text' => $t('View the control')]
                    ),
                ];

            case 'kri_alert':
            case 'kri_cleared':
                $advice = $key === 'kri_alert'
                    ? $t('Analyse the cause, decide on a treatment and, if needed, create the ticket proposed on the '
                        . 'indicator form.')
                    : $t('The indicator is back below its thresholds. Check whether the related ticket, if any, can '
                        . 'be closed.');
                $title = $head('##kri.action##', '##kri.name##');
                $text = $title . "\n\n"
                    . sprintf($t('Status: %1$s (before: %2$s)'), '##kri.status##', '##kri.previousstatus##') . "\n"
                    . sprintf($t('Last value: %1$s, recorded on %2$s'), '##kri.value##', '##kri.date##') . "\n"
                    . sprintf($t('Thresholds: orange %1$s, red %2$s'), '##kri.warning##', '##kri.critical##') . "\n"
                    . $line('Source', '##kri.source##') . "\n"
                    . $line('Entity', '##kri.entity##') . "\n"
                    . $line('Owner', '##kri.owner##') . "\n\n"
                    . $advice . "\n\n"
                    . $line('View the indicator', '##kri.url##');

                return [
                    'subject'      => $title,
                    'content_text' => $text,
                    'content_html' => NotificationHtml::renderBranded(
                        '##kri.brandcolor##',
                        $title,
                        '##kri.category##',
                        self::rows($t, [
                            'Status'           => '##kri.status##',
                            'Previous status'  => '##kri.previousstatus##',
                            'Last value'       => '##kri.value##',
                            'Recorded on'      => '##kri.date##',
                            'Orange threshold' => '##kri.warning##',
                            'Red threshold'    => '##kri.critical##',
                            'Source'           => '##kri.source##',
                            'Entity'           => '##kri.entity##',
                            'Owner'            => '##kri.owner##',
                        ]),
                        null,
                        [$advice],
                        ['url' => '##kri.url##', 'text' => $t('View the indicator')]
                    ),
                ];

            case 'bia_review':
            case 'recovery_test':
                $bia = $key === 'bia_review';
                $rows = self::rows($t, ($bia ? [
                    'To review on' => '##businessprocess.duedate##',
                    'Last BIA'     => '##businessprocess.biadate##',
                ] : [
                    'Test due on'   => '##businessprocess.duedate##',
                    'Last test'     => '##businessprocess.lasttest##',
                    'Measured time' => '##businessprocess.measured##',
                    'RTO'           => '##businessprocess.rto##',
                ]) + [
                    'Owner'       => '##businessprocess.owner##',
                    'Criticality' => '##businessprocess.criticity##',
                    'Entity'      => '##businessprocess.entity##',
                ]);
                $advice = $bia
                    ? $t('Review with the owner the impacts of an interruption over time, the MTPD, the RTO and the '
                        . 'RPO, then record the BIA in the "Business impact analysis (BIA)" tab of the process.')
                    : $t('Plan a test or a crisis exercise, then record the measured recovery time in the "Recovery '
                        . 'strategy" tab of the process.');
                $button = $t($bia ? 'View the BIA' : 'View the recovery strategy');
                $text = $head('##businessprocess.duestatus##', '##businessprocess.action##') . "\n"
                    . $line('Process', '##businessprocess.name##') . "\n\n";
                foreach ($rows as $row) {
                    $text .= $head($row['label'], $row['tag']) . "\n";
                }

                return [
                    'subject'      => $head(
                        '##businessprocess.duestatus##',
                        '##businessprocess.action## - ##businessprocess.name##'
                    ),
                    'content_text' => $text . "\n" . $advice . "\n\n" . $head($button, '##businessprocess.url##'),
                    'content_html' => NotificationHtml::renderBranded(
                        '##businessprocess.brandcolor##',
                        $head('##businessprocess.duestatus##', '##businessprocess.name##'),
                        '##businessprocess.action##',
                        $rows,
                        null,
                        [$advice],
                        ['url' => '##businessprocess.url##', 'text' => $button]
                    ),
                ];

            case 'training_renewal':
                $title = $head('##training.action##', '##training.title##');

                return self::simple($title, $title, [
                    $line('Format', '##training.format##'),
                    $line('Renewal (months)', '##training.renewalmonths##'),
                ], [], '##training.url##', $t('View the training'), $head);

            case 'accesswatch_changed':
            case 'accesswatch_disabled':
                return [
                    'subject'      => '##accesswatch.action##',
                    'content_text' => "##accesswatch.action##\n\n##accesswatch.changes##\n\n"
                        . $t('Nothing was changed automatically.') . ' ' . $line('Audit log', '##accesswatch.url##'),
                    'content_html' => NotificationHtml::render([
                        NotificationHtml::strong('##accesswatch.action##'),
                        NotificationHtml::lines(['##accesswatch.changes##', $t('Nothing was changed automatically.')]),
                        NotificationHtml::link('##accesswatch.url##', $t('Open the audit log')),
                    ]),
                ];

            case 'breach_exposures':
                $title = $head('##breachexposure.action##', '##breachexposure.count##');

                return [
                    'subject'      => $title,
                    'content_text' => $title . "\n\n" . $t('Work addresses found in known data breaches.') . ' '
                        . $line('Details in the register (restricted access)', '##breachexposure.url##'),
                    'content_html' => NotificationHtml::render([
                        NotificationHtml::strong($title),
                        NotificationHtml::lines([
                            $t('Work addresses found in known data breaches.'),
                            $t('Details in the register (restricted access).'),
                        ]),
                        NotificationHtml::link('##breachexposure.url##', $t('Open the register')),
                    ]),
                ];

            case 'vulnerability_digest':
                $colors = ['#d63939', '#f76707', '#f59f00', '#1a1a2e', '#1a1a2e', '#1a1a2e'];
                $rows = [];
                foreach (
                    [
                        'Actively exploited (CISA KEV)' => '##vulndigest.exploited##',
                        'Probably exploited (EPSS)'     => '##vulndigest.likely##',
                        'Critical (CVSS 9 or more)'     => '##vulndigest.critical##',
                        'High (CVSS below 9)'           => '##vulndigest.high##',
                        'Exposed machines'              => '##vulndigest.machines##',
                        'New since the last report'     => '##vulndigest.new##',
                    ] as $label => $tag
                ) {
                    $rows[] = ['label' => $t($label), 'tag' => $tag, 'color' => $colors[count($rows)]];
                }
                $advice = $t('Handle the exploited vulnerabilities first: update the software or apply the vendor\'s '
                    . 'workaround. The full list, the affected machines and ticket creation are in GLPI.');
                $text = "##vulndigest.subject##\n\n";
                foreach ($rows as $row) {
                    $text .= $head($row['label'], $row['tag']) . "\n";
                }
                $text .= "##vulndigest.ransomware##\n\n##FOREACHvulnerabilities##"
                    . sprintf(
                        $t('- %1$s: %2$s (%3$s), %4$s, %5$s, %6$s machine(s) %7$s'),
                        '##vulnerability.cve##',
                        '##vulnerability.product##',
                        '##vulnerability.versions##',
                        '##vulnerability.category##',
                        '##vulnerability.signal##',
                        '##vulnerability.machines##',
                        '##vulnerability.isnew##'
                    )
                    . "\n##ENDFOREACHvulnerabilities####vulndigest.more##\n\n" . $advice . "\n\n"
                    . $line('View the asset vulnerabilities', '##vulndigest.url##');

                return [
                    'subject'      => '##vulndigest.subject##',
                    'content_text' => $text,
                    'content_html' => NotificationHtml::renderVulnerabilityDigest(
                        $t('Asset vulnerability report'),
                        $rows,
                        ['CVE', $t('Software'), $t('Category'), $t('Machines')],
                        [$advice],
                        $t('View the asset vulnerabilities')
                    ),
                ];

            case 'policy_review':
                $title = $head('##policy.action##', '##policy.title##');

                return self::simple($title, $title, [
                    $line('Version', '##policy.version##'),
                    $line('Status', '##policy.status##'),
                    $line('Next review', '##policy.reviewdate##'),
                ], [], '##policy.url##', $t('View the policy'), $head);

            case 'treatmentaction_overdue':
                $title = $head('##treatmentaction.action##', '##treatmentaction.risktitle##');

                return self::simple($title, $title, [
                    $line('Action', '##treatmentaction.description##'),
                    $line('Due date', '##treatmentaction.duedate##'),
                ], [], '##treatmentaction.url##', $t('View the risk'), $head);

            case 'soa_approval':
                $ask = sprintf(
                    $t('%1$s asks you to approve version %2$s of the Statement of Applicability (%3$s).'),
                    '##soa.requester##',
                    '##soa.version##',
                    '##soa.date##'
                );
                $link = $t('Read the frozen version and answer');

                return [
                    'subject'      => $head('##soa.action##', sprintf($t('version %s'), '##soa.version##')),
                    'content_text' => $ask . "\n\n##soa.comment##\n\n" . $head($link, '##soa.url##'),
                    'content_html' => NotificationHtml::render([
                        NotificationHtml::lines([$ask]),
                        NotificationHtml::lines(['##soa.comment##']),
                        NotificationHtml::link('##soa.url##', $link),
                    ]),
                ];

            case 'accessreview_request':
            case 'accessreview_reminder':
                if ($key === 'accessreview_request') {
                    $subject = $line('Access review to complete', '##accessreview.name##');
                    $text = sprintf(
                        $t('%1$s asks you to review the access rights of your team\'s accounts before %2$s '
                            . '(campaign "%3$s").'),
                        '##accessreview.owner##',
                        '##accessreview.duedate##',
                        '##accessreview.name##'
                    );
                    $link = $t('Complete my access review');
                } else {
                    $subject = sprintf($t('Reminder: access review %s'), '##accessreview.name##');
                    $text = sprintf(
                        $t('The %1$s deadline has passed and accounts of your team are still waiting for your decision '
                            . '(campaign "%2$s").'),
                        '##accessreview.duedate##',
                        '##accessreview.name##'
                    );
                    $link = $t('Finish my access review');
                }

                return [
                    'subject'      => $subject,
                    'content_text' => $text . "\n\n" . $head($link, '##accessreview.url##'),
                    'content_html' => NotificationHtml::render([
                        NotificationHtml::lines([$text]),
                        NotificationHtml::link('##accessreview.url##', $link),
                    ]),
                ];

            case 'policy_acknowledgement':
            case 'policy_acknowledgement_reminder':
                if ($key === 'policy_acknowledgement') {
                    $subject = $line('Security policy to acknowledge', '##policy.title##');
                    $text = sprintf(
                        $t('Please read version %1$s of the security policy "%2$s" and confirm that you have read it '
                            . 'before %3$s.'),
                        '##policy.version##',
                        '##policy.title##',
                        '##policy.duedate##'
                    );
                } else {
                    $subject = sprintf($t('Reminder: security policy %s to acknowledge'), '##policy.title##');
                    $text = sprintf(
                        $t('The %1$s deadline has passed and you have not yet confirmed that you have read version '
                            . '%2$s of the security policy "%3$s".'),
                        '##policy.duedate##',
                        '##policy.version##',
                        '##policy.title##'
                    );
                }
                $link = $t('Read and confirm');

                return [
                    'subject'      => $subject,
                    'content_text' => $text . "\n\n" . $head($link, '##policy.acknowledgeurl##'),
                    'content_html' => NotificationHtml::render([
                        NotificationHtml::lines([$text]),
                        NotificationHtml::link('##policy.acknowledgeurl##', $link),
                    ]),
                ];
        }

        throw new \InvalidArgumentException('Unknown notification template: ' . $key);
    }

    /**
     * Même contenu, à la mise en forme près : l'objet et le texte sont comparés aux espaces près, le
     * HTML par son texte seul (balises retirées), car le rendu HTML a pu changer entre deux versions.
     *
     * @param array<string, mixed> $row ligne de glpi_notificationtemplatetranslations
     * @param array{subject: string, content_text: string, content_html: string} $expected
     */
    public static function sameContent(array $row, array $expected): bool
    {
        return self::normalize((string) ($row['subject'] ?? '')) === self::normalize($expected['subject'])
            && self::normalize((string) ($row['content_text'] ?? '')) === self::normalize($expected['content_text'])
            && self::htmlText((string) ($row['content_html'] ?? '')) === self::htmlText($expected['content_html']);
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    private static function htmlText(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Contenu enregistré échappé une seconde fois par certaines versions de GLPI.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalize(strip_tags((string) preg_replace('/<(br|\/p|\/tr|\/td|\/th)[^>]*>/i', ' ', $text)));
    }

    /**
     * @param callable(string): string $t
     * @param array<string, string> $labels msgid => balise
     * @return list<array{label: string, tag: string}>
     */
    private static function rows(callable $t, array $labels): array
    {
        $rows = [];
        foreach ($labels as $label => $tag) {
            $rows[] = ['label' => $t($label), 'tag' => $tag];
        }

        return $rows;
    }

    /**
     * Modèle simple : titre en gras, lignes de détail, paragraphe de conseil éventuel, lien.
     *
     * @param list<string> $lines
     * @param list<string> $advice
     * @param \Closure(string, string): string $head « gauche : droite » dans la langue du modèle
     * @return array{subject: string, content_text: string, content_html: string}
     */
    private static function simple(
        string $subject,
        string $title,
        array $lines,
        array $advice,
        string $url,
        string $linkText,
        \Closure $head
    ): array {
        $text = $title . "\n\n" . implode("\n", $lines) . "\n\n";
        foreach ($advice as $paragraph) {
            $text .= $paragraph . "\n\n";
        }
        $blocks = [NotificationHtml::strong($title), NotificationHtml::lines($lines)];
        if ($advice !== []) {
            $blocks[] = NotificationHtml::lines($advice);
        }
        $blocks[] = NotificationHtml::link($url, $linkText);

        return [
            'subject'      => $subject,
            'content_text' => $text . $head($linkText, $url),
            'content_html' => NotificationHtml::render($blocks),
        ];
    }

    /**
     * Modèle au texte seul, HTML dérivé du texte (aucun gabarit HTML écrit en PHP).
     *
     * @return array{subject: string, content_text: string, content_html: string}
     */
    private static function plain(string $subject, string $text): array
    {
        return ['subject' => $subject, 'content_text' => $text, 'content_html' => nl2br(htmlescape($text))];
    }
}

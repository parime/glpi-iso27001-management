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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;

/**
 * Hooks::DASHBOARD_CARDS callback (registered in setup.php).
 *
 * The nullable, accumulator-merging signature is deliberate from the very first commit of this
 * plugin: the sibling plugin glpi-vulnerability-manager (same author) shipped a bare
 * `array $cards = []` signature first and had to fix it after finding, against a real instance
 * running alongside another plugin also hooking DASHBOARD_CARDS, that Plugin::doHookFunction()
 * chains every registered callback through the same accumulator
 * (`$ret = call_user_func($function, $ret)`, never array_merge()-ing the results itself) — a
 * callback that ignores the incoming array and returns only its own cards silently discards every
 * other plugin's contribution whenever it runs later in the chain, and gets discarded itself when
 * it runs earlier. The parameter must accept null, not just default to an empty array: Grid.php
 * calls the very first plugin in the chain as call_user_func($function, null), an explicit null
 * that does NOT fall through to a `array $cards = []` default (PHP only applies a default when
 * the argument is omitted, not when null is passed for a non-nullable type).
 */
function plugin_grcmanager_dashboard_cards(?array $cards = null): array
{
    $cards ??= [];

    $group = __('GRC Manager', 'grcmanager');

    $cards += [
        'grcmanager_open_risks' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Risques ouverts', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::openRisksCount',
        ],
        'grcmanager_risks_by_level' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques par niveau', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::risksByLevel',
        ],
        'grcmanager_risks_by_category' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques par catégorie', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::risksByCategory',
        ],
        'grcmanager_risks_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Risques en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::risksPendingReviewCount',
        ],
        // Issue #145 : preuves expirées ou expirant sous 30 jours.
        'grcmanager_evidences_to_renew' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Preuves expirées ou à renouveler', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::evidencesToRenewCount',
        ],
        // Sprint 3 (SoA, clause 6.1.3).
        'grcmanager_soa_reviewed' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Contrôles SoA revus', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::soaReviewedCount',
        ],
        'grcmanager_soa_by_applicability' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Contrôles SoA par applicabilité', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::soaByApplicability',
        ],
        'grcmanager_soa_by_implementation_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Contrôles SoA par état de mise en œuvre', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::soaByImplementationStatus',
        ],
        // Sprint 4 (audits internes et CAPA, clause 9.2/10.2).
        'grcmanager_open_nonconformities' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Non-conformités ouvertes', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::openNonconformitiesCount',
        ],
        'grcmanager_overdue_capa' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Actions correctives/préventives en retard', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::overdueCapaCount',
        ],
        'grcmanager_audits_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Audits internes par statut', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::auditsByStatus',
        ],
        // Sprint 5 (risques fournisseurs/tiers).
        'grcmanager_supplierrisks_by_level' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques fournisseurs par niveau', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::supplierRisksByLevel',
        ],
        'grcmanager_suppliers_with_high_risk' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Fournisseurs avec au moins un risque élevé/critique ouvert', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::suppliersWithHighRiskCount',
        ],
        // Issue #143 : cartes de pilotage (conformité par référentiel, échéances, revue des accès,
        // délais réglementaires, fournisseurs, revues périodiques).
        'grcmanager_compliance_by_framework' => [
            'widgettype' => ['hbar', 'bar'],
            'label' => __('Mesures mises en œuvre par référentiel (%)', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::complianceByFramework',
        ],
        'grcmanager_upcoming_compliance_deadlines' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Échéances à 90 jours', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::upcomingComplianceDeadlines',
        ],
        'grcmanager_access_review_progress' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Revue des accès : % revus', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::accessReviewProgress',
        ],
        'grcmanager_security_incident_regulatory_deadlines' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Délais NIS2 / RGPD en alerte', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::securityIncidentRegulatoryDeadlines',
        ],
        'grcmanager_supplier_questionnaire_average' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Score fournisseurs moyen', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::supplierQuestionnaireAverage',
        ],
        'grcmanager_periodic_reviews_overdue' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Revues périodiques en retard', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::periodicReviewsOverdue',
        ],
        'grcmanager_critical_suppliers_without_assessment' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Fournisseurs critiques non évalués', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::criticalSuppliersWithoutAssessment',
        ],
        'grcmanager_monthly_trend' => [
            'widgettype' => ['lines', 'areas'],
            'label' => __('Risques et non-conformités par mois', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::monthlyTrend',
        ],
        'grcmanager_access_review_late_reviewers' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Responsables en retard (accès)', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::accessReviewLateReviewers',
        ],
        'grcmanager_training_retake_participants' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Participants à repasser', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::trainingRetakeParticipants',
        ],
        'grcmanager_training_pass_rate_by_session' => [
            'widgettype' => ['hbar', 'bar'],
            'label' => __('Réussite par session de formation (%)', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::trainingPassRateBySession',
        ],
        // Issue #166 : score de maturité cyber (0 à 100), détail sur la page « Score de maturité ».
        'grcmanager_maturity_score' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Score de maturité', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::maturityScore',
        ],
        // Sprint 6 (formations et revues de direction, clauses 7.2/7.3/9.3).
        'grcmanager_training_completion_rate' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Taux de réalisation des formations', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::trainingCompletionRate',
        ],
        'grcmanager_training_pass_rate' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Taux de réussite des formations évaluées', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::trainingPassRate',
        ],
        'grcmanager_training_overdue_renewal' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Participants en retard de renouvellement de formation', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::trainingOverdueRenewalCount',
        ],
        'grcmanager_management_reviews_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Revues de direction par statut', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::managementReviewsByStatus',
        ],
        // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1).
        'grcmanager_policies_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Politiques en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::policiesPendingReviewCount',
        ],
        'grcmanager_policies_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Politiques de sécurité par statut', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::policiesByStatus',
        ],
        // Issue #30 (registre des obligations légales/réglementaires/contractuelles, clause 4.2).
        'grcmanager_obligations_non_compliant' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Obligations non conformes', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::obligationsNonCompliantCount',
        ],
        'grcmanager_obligations_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Obligations en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::obligationsPendingReviewCount',
        ],
        'grcmanager_obligations_by_type' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Obligations par type', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::obligationsByType',
        ],
        'grcmanager_obligations_by_compliance_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Obligations par statut de conformité', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::obligationsByComplianceStatus',
        ],
        // Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2).
        'grcmanager_objectives_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Objectifs ISMS par statut', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::objectivesByStatus',
        ],
        // Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27).
        'grcmanager_security_incidents_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Incidents de sécurité par statut', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::securityIncidentsByStatus',
        ],
        'grcmanager_security_incidents_by_severity' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Incidents de sécurité par sévérité', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::securityIncidentsBySeverity',
        ],
        // Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3).
        'grcmanager_overdue_treatment_actions' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Actions de traitement de risque en retard', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::overdueTreatmentActionsCount',
        ],
        'grcmanager_risks_missing_treatment_plan' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Risques à mitiger/transférer sans plan de traitement', 'grcmanager'),
            'group' => $group,
            'provider' => SecuredDashboardCards::class . '::risksMissingTreatmentPlanCount',
        ],
    ];

    // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : les 4 cartes natives
    // portées depuis ce plugin jumeau (total/ouverts/répartition par entité et par catégorie),
    // conditionnées par le module ET son propre interrupteur "cartes de tableau de bord" - un
    // administrateur qui désactive l'un ou l'autre ne voit plus jamais une carte vide. `total`/
    // `by_entity`/`by_category` réutilisent directement les fournisseurs génériques
    // `Glpi\Dashboard\Provider::bigNumber<Itemtype>`/`multipleNumber<Itemtype>By<FkItemtype>`
    // (magic __callStatic, gèrent déjà entités/is_deleted pour tout CommonDBTM) ; seul "ouverts" a
    // besoin d'une requête dédiée (SecurityIncidentCardProvider::open()), sa définition de "ouvert"
    // dépendant des constantes de statut propres à cet objet.
    $moduleFlags = SecurityIncidentModuleConfig::load();
    if ($moduleFlags['securityincident_enabled'] && $moduleFlags['securityincident_dashboard_enabled']) {
        $itilItemtype = \PluginGrcmanagerSecurityIncident::class;
        $itilTable    = $itilItemtype::getTable();
        $itilFilters  = \Glpi\Dashboard\Filter::getAppliableFilters($itilTable);

        $cards += [
            'securityincidents_bn_total' => [
                'widgettype' => ['bigNumber'],
                'itemtype'   => "\\{$itilItemtype}",
                'label'      => __('Incidents de sécurité', 'grcmanager'),
                'group'      => $group,
                // Fournisseur générique cœur (Glpi\Dashboard\Provider::__callStatic()), déjà porté
                // depuis glpi-security-incidents (ROADMAP.md "Version 2.0") - gère déjà entités/
                // is_deleted pour tout CommonDBTM.
                'provider'   => 'Glpi\\Dashboard\\Provider::bigNumber' . $itilItemtype,
                'filters'    => $itilFilters,
            ],
            'securityincidents_bn_open' => [
                'widgettype' => ['bigNumber'],
                'itemtype'   => "\\{$itilItemtype}",
                'label'      => _x('security incidents', 'Open', 'grcmanager'),
                'group'      => $group,
                'provider'   => SecuredDashboardCards::class . '::open',
                'filters'    => $itilFilters,
            ],
            'securityincidents_by_entity' => [
                'widgettype' => [
                    'summaryNumbers', 'multipleNumber', 'pie', 'donut', 'halfpie', 'halfdonut', 'bar', 'hbar',
                ],
                'itemtype'   => "\\{$itilItemtype}",
                'label'      => __('Incidents de sécurité par entité', 'grcmanager'),
                'group'      => $group,
                'provider'   => 'Glpi\\Dashboard\\Provider::multipleNumber' . $itilItemtype . 'ByEntity',
                'filters'    => $itilFilters,
            ],
            'securityincidents_by_category' => [
                'widgettype' => [
                    'summaryNumbers', 'multipleNumber', 'pie', 'donut', 'halfpie', 'halfdonut', 'bar', 'hbar',
                ],
                'itemtype'   => "\\{$itilItemtype}",
                'label'      => __('Incidents de sécurité par catégorie GLPI', 'grcmanager'),
                'group'      => $group,
                'provider'   => 'Glpi\\Dashboard\\Provider::multipleNumber' . $itilItemtype . 'ByITILCategory',
                'filters'    => $itilFilters,
            ],
            'grcmanager_security_incident_response_time' => [
                'widgettype' => ['bigNumber'],
                'label' => __('Délai moyen de réponse aux incidents (heures)', 'grcmanager'),
                'group' => $group,
                'provider' => SecuredDashboardCards::class . '::securityIncidentResponseTimeHours',
            ],
        ];
    }

    return $cards;
}

/**
 * Hooks::POST_ITIL_INFO_SECTION callback (registered in setup.php). Renders both accordions
 * absorbed from glpi-security-incidents (ROADMAP.md "Version 2.0") directly in the main ITIL
 * fields panel, exactly where Change/Problem's own native "Analyse"/"Plans" accordions live:
 * "Analyse" (impact/contrôles appliqués/plan de retour arrière, ported unchanged) and
 * "Classification ISO 27001" (catégorie/sévérité/impact C/I/D/cause racine/enseignements tirés/
 * risque lié, the fields merged from the plugin's former lightweight compliance register).
 */
/**
 * Issue #165 : section « Sécurité » dans le panneau principal d'un ticket existant — l'incident de
 * sécurité lié, ou le bouton « Escalader en incident de sécurité » (même action que l'onglet du
 * ticket, front/securityincidentticket.form.php). Rien si le module est désactivé ou sans droit.
 */
function plugin_grcmanager_ticket_security_section(Ticket $ticket): void
{
    global $CFG_GLPI;

    if (
        $ticket->isNewItem()
        || !SecurityIncidentModuleConfig::load()['securityincident_enabled']
        || !PluginGrcmanagerSecurityIncident::canView()
    ) {
        return;
    }

    $incidents = [];
    foreach (PluginGrcmanagerSecurityIncidentTicket::getIncidentIdsForTicket((int) $ticket->getID()) as $id) {
        $incident = new PluginGrcmanagerSecurityIncident();
        if ($incident->getFromDB($id) && $incident->canViewItem()) {
            $incidents[] = [
                'id'     => $id,
                'name'   => (string) $incident->fields['name'],
                'url'    => PluginGrcmanagerSecurityIncident::getFormURLWithID($id),
                'status' => PluginGrcmanagerSecurityIncident::getStatus((int) $incident->fields['status']),
            ];
        }
    }

    Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/ticket_security_section.html.twig', [
        'incidents'  => $incidents,
        'can_create' => PluginGrcmanagerSecurityIncident::canCreate() && $ticket->can($ticket->getID(), READ),
        'action'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/securityincidentticket.form.php',
        'tickets_id' => (int) $ticket->getID(),
    ]);
}

function plugin_grcmanager_post_itil_info_section(array $params): void
{
    $item = $params['item'] ?? null;
    if ($item instanceof Ticket) {
        plugin_grcmanager_ticket_security_section($item);
        return;
    }
    if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
        return;
    }

    $renderer = Glpi\Application\View\TemplateRenderer::getInstance();
    $rand     = mt_rand();

    $renderer->display('@grcmanager/itil_analysis_section.html.twig', [
        'item' => $item,
        'rand' => $rand,
    ]);

    global $DB;

    $risks = [0 => Dropdown::EMPTY_VALUE];
    foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => PluginGrcmanagerRisk::getTable()]) as $row) {
        $risks[(int) $row['id']] = $row['title'];
    }

    $renderer->display('@grcmanager/itil_classification_section.html.twig', [
        'item'           => $item,
        'rand'           => $rand,
        'categories'     => PluginGrcmanagerSecurityIncident::getCategories(),
        'severities'     => PluginGrcmanagerSecurityIncident::getSeverities(),
        'cia_axes'       => PluginGrcmanagerSecurityIncident::getCiaAxisLabels(),
        'risks'          => $risks,
        'risk_type_name' => PluginGrcmanagerRisk::getTypeName(1),
    ]);

    // Issue #128 : délais de notification réglementaires (NIS2, RGPD).
    $renderer->display('@grcmanager/itil_regulatory_section.html.twig', [
        'item'        => $item,
        'rand'        => $rand,
        'deadlines'   => GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines::compute($item->fields),
        'labels'      => PluginGrcmanagerSecurityIncident::getRegulatoryLabels(),
        'done_fields' => array_map(
            static fn (array $deadline): string => $deadline[2],
            GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines::DEADLINES
        ),
    ]);
}

/**
 * Issue #128 : supprime les liens ticket <-> incident de sécurité quand l'un des deux est
 * supprimé définitivement (Hooks::ITEM_PURGE, setup.php).
 */
function plugin_grcmanager_purge_security_incident_links(CommonDBTM $item): void
{
    global $DB;

    $field = $item instanceof Ticket ? 'tickets_id' : 'plugin_grcmanager_securityincidents_id';
    if ($DB->tableExists(PluginGrcmanagerSecurityIncidentTicket::TABLE)) {
        $DB->delete(PluginGrcmanagerSecurityIncidentTicket::TABLE, [$field => (int) $item->getID()]);
    }
}

/**
 * Issue #141 : Hooks::ITEM_ADD sur Ticket (setup.php), voir PluginGrcmanagerPeriodicReview::onTicketAdd().
 */
function plugin_grcmanager_periodic_review_ticket_add(CommonDBTM $item): void
{
    if ($item instanceof Ticket) {
        PluginGrcmanagerPeriodicReview::onTicketAdd($item);
    }
}

/**
 * Issue #141 : Hooks::ITEM_UPDATE sur Ticket (setup.php), voir PluginGrcmanagerPeriodicReview::onTicketUpdate().
 */
function plugin_grcmanager_periodic_review_ticket_update(CommonDBTM $item): void
{
    if ($item instanceof Ticket) {
        PluginGrcmanagerPeriodicReview::onTicketUpdate($item);
    }
}

/**
 * Issue #165 : action groupée « Qualifier en incident de sécurité » sur la liste des tickets
 * (Hooks::USE_MASSIVE_ACTION, setup.php), si le module est actif et la personne peut créer un incident.
 *
 * @return array<string, string>
 */
function plugin_grcmanager_MassiveActions(string $itemtype): array
{
    if (
        $itemtype !== Ticket::class
        || !SecurityIncidentModuleConfig::load()['securityincident_enabled']
        || !PluginGrcmanagerSecurityIncident::canCreate()
    ) {
        return [];
    }

    return [
        PluginGrcmanagerSecurityIncidentTicket::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'qualify'
            => '<i class="ti ti-shield-plus"></i>' . htmlescape(__('Qualifier en incident de sécurité', 'grcmanager')),
    ];
}

function plugin_grcmanager_install(): bool
{
    $migration = new Migration(PLUGIN_GRCMANAGER_VERSION);

    return (new Installer())->install($migration);
}

function plugin_grcmanager_uninstall(): bool
{
    $migration = new Migration(PLUGIN_GRCMANAGER_VERSION);

    return (new Installer())->uninstall($migration);
}

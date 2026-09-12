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
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
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
            'provider' => DashboardCardService::class . '::openRisksCount',
        ],
        'grcmanager_risks_by_level' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques par niveau', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::risksByLevel',
        ],
        'grcmanager_risks_by_category' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques par catégorie', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::risksByCategory',
        ],
        'grcmanager_risks_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Risques en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::risksPendingReviewCount',
        ],
        // Sprint 3 (SoA, clause 6.1.3).
        'grcmanager_soa_reviewed' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Contrôles SoA revus', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::soaReviewedCount',
        ],
        'grcmanager_soa_by_applicability' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Contrôles SoA par applicabilité', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::soaByApplicability',
        ],
        'grcmanager_soa_by_implementation_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Contrôles SoA par état de mise en œuvre', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::soaByImplementationStatus',
        ],
        // Sprint 4 (audits internes et CAPA, clause 9.2/10.2).
        'grcmanager_open_nonconformities' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Non-conformités ouvertes', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::openNonconformitiesCount',
        ],
        'grcmanager_overdue_capa' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Actions correctives/préventives en retard', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::overdueCapaCount',
        ],
        'grcmanager_audits_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Audits internes par statut', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::auditsByStatus',
        ],
        // Sprint 5 (risques fournisseurs/tiers).
        'grcmanager_supplierrisks_by_level' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Risques fournisseurs par niveau', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::supplierRisksByLevel',
        ],
        'grcmanager_suppliers_with_high_risk' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Fournisseurs avec au moins un risque élevé/critique ouvert', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::suppliersWithHighRiskCount',
        ],
        // Sprint 6 (formations et revues de direction, clauses 7.2/7.3/9.3).
        'grcmanager_training_completion_rate' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Taux de réalisation des formations', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::trainingCompletionRate',
        ],
        'grcmanager_training_overdue_renewal' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Participants en retard de renouvellement de formation', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::trainingOverdueRenewalCount',
        ],
        'grcmanager_management_reviews_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Revues de direction par statut', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::managementReviewsByStatus',
        ],
        // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1).
        'grcmanager_policies_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Politiques en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::policiesPendingReviewCount',
        ],
        'grcmanager_policies_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Politiques de sécurité par statut', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::policiesByStatus',
        ],
        // Issue #30 (registre des obligations légales/réglementaires/contractuelles, clause 4.2).
        'grcmanager_obligations_non_compliant' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Obligations non conformes', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::obligationsNonCompliantCount',
        ],
        'grcmanager_obligations_pending_review' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Obligations en attente de revue', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::obligationsPendingReviewCount',
        ],
        'grcmanager_obligations_by_type' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Obligations par type', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::obligationsByType',
        ],
        'grcmanager_obligations_by_compliance_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Obligations par statut de conformité', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::obligationsByComplianceStatus',
        ],
        // Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2).
        'grcmanager_objectives_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Objectifs ISMS par statut', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::objectivesByStatus',
        ],
        // Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27).
        'grcmanager_security_incidents_by_status' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Incidents de sécurité par statut', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::securityIncidentsByStatus',
        ],
        'grcmanager_security_incidents_by_severity' => [
            'widgettype' => ['multipleNumber', 'pie', 'donut', 'bar', 'hbar'],
            'label' => __('Incidents de sécurité par sévérité', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::securityIncidentsBySeverity',
        ],
        // Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3).
        'grcmanager_overdue_treatment_actions' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Actions de traitement de risque en retard', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::overdueTreatmentActionsCount',
        ],
        'grcmanager_risks_missing_treatment_plan' => [
            'widgettype' => ['bigNumber'],
            'label' => __('Risques à mitiger/transférer sans plan de traitement', 'grcmanager'),
            'group' => $group,
            'provider' => DashboardCardService::class . '::risksMissingTreatmentPlanCount',
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
                'provider'   => SecurityIncidentCardProvider::class . '::open',
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
                'provider' => DashboardCardService::class . '::securityIncidentResponseTimeHours',
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
function plugin_grcmanager_post_itil_info_section(array $params): void
{
    $item = $params['item'] ?? null;
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

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

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #143 : cartes de pilotage du tableau de bord GRC (conformité par référentiel, échéances,
 * revue des accès, délais réglementaires, fournisseurs, revues périodiques). Les droits sont
 * vérifiés par SecuredDashboardCards, comme pour les autres cartes.
 */
final class PilotageCards
{
    /**
     * Taux de mise en œuvre des mesures applicables de l'Annexe A liées à chaque référentiel :
     * toutes pour ISO 27001, celles qui répondent à une exigence NIS2 ou à une mesure du guide
     * d'hygiène de l'ANSSI pour les deux autres (correspondances indicatives du plugin).
     *
     * @param array<string, array{applicability: string, implementation_status: string}> $controls code => ligne
     * @return array<string, ?float> référentiel => taux (null si aucune mesure concernée)
     */
    public static function frameworkRates(array $controls): array
    {
        $filters = [
            'ISO 27001' => static fn (string $code): bool => true,
            'NIS2'      => static fn (string $code): bool => Nis2CatalogDefaults::requirementsForControl($code) !== [],
            'ANSSI'     => static fn (string $code): bool
                => AnssiHygieneCatalogDefaults::measuresForControl($code) !== [],
        ];
        $rates = [];
        foreach ($filters as $framework => $covers) {
            $byStatus = [];
            foreach ($controls as $code => $row) {
                if ($row['applicability'] === 'no' || !$covers((string) $code)) {
                    continue;
                }
                $byStatus[$row['implementation_status']] = ($byStatus[$row['implementation_status']] ?? 0) + 1;
            }
            $rates[$framework] = MaturityScore::implementationRate($byStatus);
        }

        return $rates;
    }

    public static function complianceByFramework(array $params = []): array
    {
        global $DB;

        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['code', 'applicability', 'implementation_status'],
                'FROM'   => \PluginGrcmanagerControl::getTable(),
            ]) as $row
        ) {
            $controls[(string) $row['code']] = [
                'applicability'         => (string) $row['applicability'],
                'implementation_status' => (string) $row['implementation_status'],
            ];
        }
        $data = [];
        foreach (self::frameworkRates($controls) as $framework => $rate) {
            $data[] = ['label' => $framework, 'number' => (int) round($rate ?? 0)];
        }

        return [
            'data'  => $data,
            'label' => $params['label'] ?? __('Mesures mises en œuvre par référentiel (%)', 'grcmanager'),
            'icon'  => 'ti ti-shield-check',
        ];
    }

    public static function upcomingComplianceDeadlines(array $params = []): array
    {
        $events = \PluginGrcmanagerComplianceEvent::collect(
            date('Y-m-d 00:00:00'),
            date('Y-m-d 23:59:59', (int) strtotime('+90 days'))
        );
        $pending = array_filter($events, static fn (array $event): bool => !$event['done']);

        return [
            'number' => count($pending),
            'url'    => \PluginGrcmanagerComplianceEvent::getCalendarURL(),
            'label'  => $params['label'] ?? __('Échéances à 90 jours', 'grcmanager'),
            'icon'   => 'ti ti-calendar-event',
        ];
    }

    public static function accessReviewProgress(array $params = []): array
    {
        global $DB;

        $campaigns = \PluginGrcmanagerAccessReview::getTable();
        $decisions = [];
        foreach (
            $DB->request([
                'SELECT'     => [\PluginGrcmanagerAccessReview::LINES_TABLE . '.decision'],
                'FROM'       => \PluginGrcmanagerAccessReview::LINES_TABLE,
                'INNER JOIN' => [
                    $campaigns => [
                        'FKEY' => [
                            \PluginGrcmanagerAccessReview::LINES_TABLE => 'plugin_grcmanager_accessreviews_id',
                            $campaigns                                 => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [$campaigns . '.status' => AccessReviewLogic::STATUS_OPEN]
                    + getEntitiesRestrictCriteria($campaigns, '', '', true),
            ]) as $row
        ) {
            $decisions[] = (string) $row['decision'];
        }

        return [
            'number' => AccessReviewLogic::progress($decisions)['percent'],
            'url'    => \PluginGrcmanagerAccessReview::getSearchURL(),
            'label'  => $params['label'] ?? __('Revue des accès : % revus', 'grcmanager'),
            'icon'   => 'ti ti-user-check',
        ];
    }

    /**
     * Le nom contient « securityincident » : SecuredDashboardCards applique le droit des incidents.
     */
    public static function securityIncidentRegulatoryDeadlines(array $params = []): array
    {
        global $DB;

        $count = 0;
        foreach (
            $DB->request([
                'FROM'  => \PluginGrcmanagerSecurityIncident::getTable(),
                'WHERE' => [
                    'is_deleted'  => 0,
                    'is_exercise' => 0,
                    'NOT'         => ['status' => \PluginGrcmanagerSecurityIncident::getClosedStatusArray()],
                    'OR'          => ['nis2_applicable' => 1, 'personal_data_breach' => 1],
                ] + getEntitiesRestrictCriteria(\PluginGrcmanagerSecurityIncident::getTable(), '', '', true),
            ]) as $row
        ) {
            foreach (RegulatoryDeadlines::compute($row) as $deadline) {
                $alerting = [RegulatoryDeadlines::STATUS_OVERDUE, RegulatoryDeadlines::STATUS_DUE_SOON];
                if (in_array($deadline['status'], $alerting, true)) {
                    $count++;
                }
            }
        }

        return [
            'number' => $count,
            'url'    => \PluginGrcmanagerSecurityIncident::getSearchURL(),
            'label'  => $params['label'] ?? __('Délais NIS2 / RGPD en alerte', 'grcmanager'),
            'icon'   => 'ti ti-alarm',
        ];
    }

    public static function supplierQuestionnaireAverage(array $params = []): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('AVG(`questionnaire_score`) AS avg_score')],
            'FROM'   => \PluginGrcmanagerSupplierRisk::getTable(),
            'WHERE'  => [['NOT' => ['questionnaire_score' => null]]],
        ])->current();

        return [
            'number' => $row['avg_score'] !== null ? (int) round((float) $row['avg_score']) : 0,
            'url'    => \PluginGrcmanagerSupplierRisk::getSearchURL(),
            'label'  => $params['label'] ?? __('Score fournisseurs moyen', 'grcmanager'),
            'icon'   => 'ti ti-building-store',
        ];
    }

    public static function periodicReviewsOverdue(array $params = []): array
    {
        global $DB;

        $today = date('Y-m-d');
        $count = 0;
        foreach ($DB->request(['FROM' => \PluginGrcmanagerPeriodicReview::getTable()]) as $row) {
            $review = new \PluginGrcmanagerPeriodicReview();
            $review->getFromResultSet($row);
            if ($review->isOverdue($today)) {
                $count++;
            }
        }

        return [
            'number' => $count,
            'url'    => \PluginGrcmanagerPeriodicReview::getSearchURL(),
            'label'  => $params['label'] ?? __('Revues périodiques en retard', 'grcmanager'),
            'icon'   => 'ti ti-clock-exclamation',
        ];
    }
}

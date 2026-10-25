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
     * Responsables (réviseurs) ayant encore des comptes à revoir dans une campagne ouverte dont
     * l'échéance est dépassée.
     */
    public static function accessReviewLateReviewers(array $params = []): array
    {
        global $DB;

        $lines = \PluginGrcmanagerAccessReview::LINES_TABLE;
        $campaigns = \PluginGrcmanagerAccessReview::getTable();
        $reviewer = $DB->quoteName($lines . '.reviewers_id');
        $count = (int) $DB->request([
            'SELECT'     => [new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $reviewer . ') AS c')],
            'FROM'       => $lines,
            'INNER JOIN' => [
                $campaigns => ['FKEY' => [$lines => 'plugin_grcmanager_accessreviews_id', $campaigns => 'id']],
            ],
            'WHERE'      => [
                $campaigns . '.status'   => AccessReviewLogic::STATUS_OPEN,
                $lines . '.decision'     => AccessReviewLogic::DECISION_PENDING,
                [$campaigns . '.due_date' => ['<', date('Y-m-d')]],
                [$lines . '.reviewers_id' => ['>', 0]],
            ] + getEntitiesRestrictCriteria($campaigns, '', '', true),
        ])->current()['c'];

        return [
            'number' => $count,
            'url'    => \PluginGrcmanagerAccessReview::getSearchURL(),
            'label'  => $params['label'] ?? __('Responsables en retard (accès)', 'grcmanager'),
            'icon'   => 'ti ti-user-exclamation',
        ];
    }

    /**
     * Participants dont la dernière évaluation d'une formation (même intitulé) est échouée : une
     * réussite à une session plus récente de la même formation les retire de la liste.
     *
     * @param list<array{users_id: int, title: string, date: string, result: string}> $rows
     * @return list<int> identifiants des participants à repasser
     */
    public static function participantsToRetake(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        $latest = [];
        foreach ($rows as $row) {
            $latest[$row['users_id'] . '|' . mb_strtolower(trim($row['title']))] = $row;
        }
        $users = [];
        foreach ($latest as $row) {
            if ($row['result'] === 'failed') {
                $users[$row['users_id']] = $row['users_id'];
            }
        }

        return array_values($users);
    }

    /**
     * @return list<array{users_id: int, title: string, date: string, result: string, training: int}>
     */
    private static function assessedParticipations(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'links.users_id', 'links.assessment_result', 'links.completion_date',
                    't.id AS training', 't.title', 't.date_delivered',
                ],
                'FROM'       => 'glpi_plugin_grcmanager_trainings_users AS links',
                'INNER JOIN' => [
                    'glpi_plugin_grcmanager_trainings AS t' => [
                        'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                    ],
                ],
                'WHERE'      => ['links.assessment_result' => ['passed', 'failed']],
            ]) as $row
        ) {
            $rows[] = [
                'users_id' => (int) $row['users_id'],
                'title'    => (string) $row['title'],
                'date'     => (string) ($row['completion_date'] ?? $row['date_delivered'] ?? ''),
                'result'   => (string) $row['assessment_result'],
                'training' => (int) $row['training'],
            ];
        }

        return $rows;
    }

    public static function trainingRetakeParticipants(array $params = []): array
    {
        return [
            'number' => count(self::participantsToRetake(self::assessedParticipations())),
            'url'    => \PluginGrcmanagerTraining::getSearchURL(),
            'label'  => $params['label'] ?? __('Participants à repasser', 'grcmanager'),
            'icon'   => 'ti ti-repeat',
        ];
    }

    /**
     * Taux de réussite des 8 dernières sessions évaluées.
     */
    public static function trainingPassRateBySession(array $params = []): array
    {
        global $DB;

        $sessions = [];
        foreach (self::assessedParticipations() as $row) {
            $sessions[$row['training']] ??= ['passed' => 0, 'total' => 0];
            $sessions[$row['training']]['total']++;
            if ($row['result'] === 'passed') {
                $sessions[$row['training']]['passed']++;
            }
        }
        $data = [];
        if ($sessions !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'title', 'date_delivered'],
                    'FROM'   => \PluginGrcmanagerTraining::getTable(),
                    'WHERE'  => ['id' => array_keys($sessions)],
                    'ORDER'  => ['date_delivered DESC', 'id DESC'],
                    'LIMIT'  => 8,
                ]) as $training
            ) {
                $counts = $sessions[(int) $training['id']];
                $delivered = (string) $training['date_delivered'];
                $date = $delivered !== '' ? ' (' . \Html::convDate($delivered) . ')' : '';
                $data[] = [
                    'label'  => $training['title'] . $date,
                    'number' => (int) round($counts['passed'] / $counts['total'] * 100),
                ];
            }
        }

        return [
            'data'  => array_reverse($data),
            'label' => $params['label'] ?? __('Réussite par session de formation (%)', 'grcmanager'),
            'icon'  => 'ti ti-certificate',
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

    /**
     * Questionnaires remplis seulement : questionnaire_score vaut 0 par défaut, c'est la date du
     * questionnaire qui indique qu'il a été rempli.
     */
    public static function supplierQuestionnaireAverage(array $params = []): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('AVG(`questionnaire_score`) AS avg_score')],
            'FROM'   => \PluginGrcmanagerSupplierRisk::getTable(),
            'WHERE'  => [['NOT' => ['questionnaire_date' => null]], ['NOT' => ['questionnaire_score' => null]]],
        ])->current();

        return [
            'number' => $row['avg_score'] !== null ? (int) round((float) $row['avg_score']) : 0,
            'url'    => \PluginGrcmanagerSupplierRisk::getSearchURL(),
            'label'  => $params['label'] ?? __('Score fournisseurs moyen', 'grcmanager'),
            'icon'   => 'ti ti-building-store',
        ];
    }

    /**
     * Fournisseurs ayant un risque ouvert élevé ou critique (même définition que
     * DashboardCardService::suppliersWithHighRiskCount()) dont le questionnaire de sécurité n'a
     * jamais été rempli.
     */
    public static function criticalSuppliersWithoutAssessment(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT `suppliers_id`) AS c')],
            'FROM'   => \PluginGrcmanagerSupplierRisk::getTable(),
            'WHERE'  => [
                'risk_level'          => ['high', 'critical'],
                'status'              => ['identified', 'in_treatment'],
                'questionnaire_date'  => null,
                ['suppliers_id' => ['>', 0]],
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'url'    => \PluginGrcmanagerSupplierRisk::getSearchURL(),
            'label'  => $params['label'] ?? __('Fournisseurs critiques non évalués', 'grcmanager'),
            'icon'   => 'ti ti-building-warehouse',
        ];
    }

    /**
     * Indicateurs suivis mois par mois pour la revue de direction, enregistrés avec le score de
     * maturité (tâche automatique mensuelle « maturitysnapshot »).
     *
     * @return array{open_risks: int, open_nonconformities: int}
     */
    public static function currentIndicators(): array
    {
        return [
            'open_risks'           => (int) DashboardCardService::openRisksCount()['number'],
            'open_nonconformities' => (int) DashboardCardService::openNonconformitiesCount()['number'],
        ];
    }

    /**
     * Séries de la courbe de tendance : les 12 derniers mois enregistrés. Un mois enregistré avant
     * l'issue #143 (sans indicateurs) est ignoré.
     *
     * @param array<string, ?array<string, int>> $byMonth mois (AAAA-MM) => indicateurs
     * @return array{labels: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    public static function trendSeries(array $byMonth): array
    {
        $byMonth = array_filter($byMonth, static fn (?array $indicators): bool => $indicators !== null);
        ksort($byMonth);
        $byMonth = array_slice($byMonth, -12, null, true);
        $names = [
            'open_risks'           => __('Risques ouverts', 'grcmanager'),
            'open_nonconformities' => __('Non-conformités ouvertes', 'grcmanager'),
        ];
        $result = ['labels' => array_map('strval', array_keys($byMonth)), 'series' => []];
        foreach ($names as $key => $name) {
            $data = [];
            foreach ($byMonth as $indicators) {
                $data[] = (int) ($indicators[$key] ?? 0);
            }
            $result['series'][] = ['name' => $name, 'data' => $data];
        }

        return $result;
    }

    /**
     * Le mois en cours est toujours recalculé, les mois passés viennent des enregistrements.
     */
    public static function monthlyTrend(array $params = []): array
    {
        global $DB;

        $byMonth = [];
        foreach (
            $DB->request([
                'SELECT' => ['month', 'indicators'],
                'FROM'   => \PluginGrcmanagerMaturity::SNAPSHOTS_TABLE,
                'ORDER'  => 'month DESC',
                'LIMIT'  => 12,
            ]) as $row
        ) {
            $decoded = json_decode((string) $row['indicators'], true);
            $byMonth[(string) $row['month']] = is_array($decoded) ? $decoded : null;
        }
        $byMonth[date('Y-m')] = self::currentIndicators();

        return [
            'data'  => self::trendSeries($byMonth),
            'label' => $params['label'] ?? __('Risques et non-conformités par mois', 'grcmanager'),
            'icon'  => 'ti ti-chart-line',
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

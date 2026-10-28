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

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #166 : score de maturité cyber (page, carte du tableau de bord et historique mensuel
 * pour la revue de direction). Le détail du calcul est toujours affiché : aucune boîte noire.
 */
class PluginGrcmanagerMaturity extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const SNAPSHOTS_TABLE = 'glpi_plugin_grcmanager_maturitysnapshots';

    public static function getTypeName($nb = 0)
    {
        return __('Maturity score', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-gauge';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/maturity.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function getMenuContent()
    {
        // Pas d'entrée de menu vers une page qui refuserait l'accès.
        if (!Session::haveRight('plugin_grcmanager', READ)) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getComponentLabels(): array
    {
        return [
            'soa'      => __('Annex A controls implemented (SoA)', 'grcmanager'),
            'isms'     => __('ISMS requirements (clauses 4 to 10) met', 'grcmanager'),
            'evidence' => __('Evidence still valid', 'grcmanager'),
            'audit'    => __('Internal audit completed in the last 12 months', 'grcmanager'),
            'training' => __('Training completed', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            1 => __('Initial', 'grcmanager'),
            2 => __('Repeatable', 'grcmanager'),
            3 => __('Defined', 'grcmanager'),
            4 => __('Managed', 'grcmanager'),
            5 => __('Optimised', 'grcmanager'),
        ];
    }

    /**
     * Mesures applicables de la SoA, par thème puis par état de mise en œuvre.
     *
     * @return array<string, array<string, int>>
     */
    private static function soaByTheme(): array
    {
        global $DB;

        $byTheme = [];
        foreach (
            $DB->request([
                'SELECT'  => ['theme', 'implementation_status', 'COUNT' => '* AS c'],
                'FROM'    => PluginGrcmanagerControl::getTable(),
                'WHERE'   => ['applicability' => ['yes', 'partial']],
                'GROUPBY' => ['theme', 'implementation_status'],
            ]) as $row
        ) {
            $byTheme[(string) $row['theme']][(string) $row['implementation_status']] = (int) $row['c'];
        }

        return $byTheme;
    }

    /**
     * Composantes et pénalités calculées à partir des données du plugin.
     *
     * @return array{components: array<string, ?float>, penalties: array{capa_overdue: int, incidents_open: int},
     *     themes: array<string, ?float>, facts: array<string, int>}
     */
    public static function collect(): array
    {
        global $DB;

        $today = date('Y-m-d');
        $byTheme = self::soaByTheme();
        $soa = [];
        $themes = [];
        foreach ($byTheme as $theme => $statuses) {
            $themes[$theme] = MaturityScore::implementationRate($statuses);
            foreach ($statuses as $status => $count) {
                $soa[$status] = ($soa[$status] ?? 0) + $count;
            }
        }

        $isms = [];
        foreach (
            $DB->request([
                'SELECT'  => ['status', 'COUNT' => '* AS c'],
                'FROM'    => PluginGrcmanagerIsmsRequirement::getTable(),
                'GROUPBY' => 'status',
            ]) as $row
        ) {
            $key = match ((string) $row['status']) {
                IsmsClauseCatalog::STATUS_COMPLIANT   => 'implemented',
                IsmsClauseCatalog::STATUS_IN_PROGRESS => 'in_progress',
                default                               => 'not_started',
            };
            $isms[$key] = ($isms[$key] ?? 0) + (int) $row['c'];
        }

        $evidenceTotal = countElementsInTable('glpi_plugin_grcmanager_evidences');
        $evidenceValid = countElementsInTable('glpi_plugin_grcmanager_evidences', ['OR' => [
            ['valid_until' => null],
            ['valid_until' => ['>=', $today]],
        ]]);
        $recentAudits = countElementsInTable(PluginGrcmanagerAudit::getTable(), [
            'status'      => 'completed',
            'actual_date' => ['>=', date('Y-m-d', strtotime('-12 months'))],
        ]);
        $trainingEligible = countElementsInTable('glpi_plugin_grcmanager_trainings_users', [
            'completion_status' => ['<>', 'exempted'],
        ]);
        $trainingDone = countElementsInTable('glpi_plugin_grcmanager_trainings_users', [
            'completion_status' => 'completed',
        ]);
        $capaOverdue = countElementsInTable(PluginGrcmanagerNonconformity::getTable(), [
            'NOT'      => ['status' => PluginGrcmanagerNonconformity::CLOSED_STATUSES],
            'due_date' => ['<', $today],
        ]);
        $incidentsOpen = countElementsInTable(PluginGrcmanagerSecurityIncident::getTable(), [
            'is_deleted'  => 0,
            'is_exercise' => 0,
            'NOT'         => ['status' => array_merge(
                PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
                PluginGrcmanagerSecurityIncident::getClosedStatusArray()
            )],
        ]);

        return [
            'components' => [
                'soa'      => MaturityScore::implementationRate($soa),
                'isms'     => MaturityScore::implementationRate($isms),
                'evidence' => MaturityScore::rate($evidenceValid, $evidenceTotal),
                // L'audit interne est une exigence (9.2) : son absence compte pour zéro.
                'audit'    => $recentAudits > 0 ? 100.0 : 0.0,
                'training' => MaturityScore::rate($trainingDone, $trainingEligible),
            ],
            'penalties'  => ['capa_overdue' => $capaOverdue, 'incidents_open' => $incidentsOpen],
            'themes'     => $themes,
            'facts'      => [
                'soa_applicable'  => array_sum($soa),
                'isms_total'      => array_sum($isms),
                'evidence_total'  => $evidenceTotal,
                'evidence_valid'  => $evidenceValid,
                'audits_recent'   => $recentAudits,
                'training_total'  => $trainingEligible,
                'training_done'   => $trainingDone,
            ],
        ];
    }

    /**
     * @return array{score: int, level: int, base: float, penalty: int,
     *     components: array<string, array{value: ?float, weight: int, effective_weight: float}>,
     *     penalties: array{capa_overdue: int, incidents_open: int}, themes: array<string, ?float>,
     *     facts: array<string, int>}
     */
    public static function current(): array
    {
        $data = self::collect();

        return MaturityScore::compute($data['components'], $data['penalties']) + $data;
    }

    /**
     * Enregistre (ou remplace) le score du mois en cours.
     */
    public static function snapshot(): int
    {
        global $DB;

        $current = self::current();
        $month = date('Y-m');
        $fields = [
            'score'      => $current['score'],
            'level'      => $current['level'],
            'details'    => json_encode($current['components']),
            // Issue #143 : tendance mensuelle des risques et non-conformités ouverts.
            'indicators' => json_encode(PilotageCards::currentIndicators()),
            'date_mod'   => date('Y-m-d H:i:s'),
        ];
        if ($DB->request(['FROM' => self::SNAPSHOTS_TABLE, 'WHERE' => ['month' => $month]])->count() > 0) {
            $DB->update(self::SNAPSHOTS_TABLE, $fields, ['month' => $month]);
        } else {
            $DB->insert(self::SNAPSHOTS_TABLE, ['month' => $month] + $fields);
        }

        return $current['score'];
    }

    /**
     * Les 12 derniers mois enregistrés, du plus ancien au plus récent.
     *
     * @return list<array{month: string, score: int}>
     */
    public static function history(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => self::SNAPSHOTS_TABLE, 'ORDER' => 'month DESC', 'LIMIT' => 12]) as $row) {
            $rows[] = ['month' => (string) $row['month'], 'score' => (int) $row['score']];
        }

        return array_reverse($rows);
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'maturitysnapshot'
            ? ['description' => __('Maturity score: records the score of the month', 'grcmanager')]
            : [];
    }

    public static function cronMaturitysnapshot(CronTask $task): int
    {
        $task->addVolume(1);
        $task->log(sprintf('Score de maturité : %d', self::snapshot()));

        return 1;
    }
}

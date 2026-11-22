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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Risk\RiskTrajectoryRules as Rules;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use Html;
use Session;

/**
 * Issue #871 : trajectoire projetée du risque (ISO/IEC 27001, clauses 6.1.3 et 8.3), en LECTURE SEULE.
 *
 * - onglet « Trajectoire » d'un risque : niveau prévu dans le temps d'après les échéances du plan de
 *   traitement, comparé à ce qui est réellement fait (actions terminées) ;
 * - carte « Trajectoire du registre » de la cartographie des risques : la somme des scores des risques
 *   traités, prévue et réelle, et l'état de chaque plan.
 *
 * Réutilise les données existantes (cotation probabilité x impact, risque résiduel visé, actions du
 * plan de traitement) ; le calcul est dans RiskTrajectoryRules. Rien n'est enregistré ni modifié.
 */
class RiskTrajectory extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    private const SORT_ORDER = [Rules::STATE_LATE => 0, Rules::STATE_ON_TRACK => 1, Rules::STATE_AHEAD => 2];

    public static function getTypeName($nb = 0)
    {
        return __('Projected trajectory', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-line';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(Risk::$rightname, READ);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Risk || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : droits vérifiés ici.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Risk || $item->isNewItem() || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        $actions = RiskTreatmentAction::getActionsForRisk($item->getID());
        $trajectory = self::forRisk($item->fields, $actions);

        $milestones = [];
        foreach ($trajectory['milestones'] as $milestone) {
            $names = [];
            foreach ($actions as $action) {
                if (substr((string) ($action['due_date'] ?? ''), 0, 10) === $milestone['date']) {
                    $names[] = (string) $action['description'];
                }
            }
            $milestones[] = $milestone + [
                'date_text' => Html::convDate($milestone['date']),
                'actions'   => $names,
                'level'     => self::levelBadge(
                    RiskScale::levelFromScore($milestone['score'], RiskMatrixConfig::size())
                ),
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/risk_trajectory_tab.html.twig', [
            'trajectory'   => $trajectory,
            'state'        => self::stateStyle($trajectory['state']),
            'charted'      => in_array($trajectory['state'], Rules::CHARTED_STATES, true),
            'plan_relevant' => TreatmentPlanRules::isTreatmentPlanRelevant(
                (string) ($item->fields['treatment'] ?? '')
            ),
            'current_level' => self::levelBadge((string) ($item->fields['risk_level'] ?? '')),
            'target_level' => self::levelBadge((string) ($item->fields['residual_level'] ?? '')),
            'planned_end'  => $trajectory['planned_end'] !== null ? Html::convDate($trajectory['planned_end']) : '',
            'milestones'   => $milestones,
            'chart'        => self::chartData($trajectory['planned'], $trajectory['actual'], $trajectory['target']),
            'heatmap_url'  => self::heatmapUrl(),
        ]);

        return true;
    }

    /**
     * Trajectoire d'un risque à partir de sa ligne en base et des actions de son plan.
     *
     * @param array<string, mixed> $risk
     * @param array<int, array<string, mixed>> $actions
     *
     * @return array<string, mixed> voir RiskTrajectoryRules::build()
     */
    public static function forRisk(array $risk, array $actions): array
    {
        $scoring = RiskMatrixConfig::scoring();
        $start = $scoring->score((string) ($risk['probability'] ?? ''), (string) ($risk['impact'] ?? ''));
        $residualProbability = (string) ($risk['residual_probability'] ?? '');
        $residualImpact = (string) ($risk['residual_impact'] ?? '');
        $target = $residualProbability !== '' && $residualImpact !== ''
            ? $scoring->score($residualProbability, $residualImpact) : null;

        $today = date('Y-m-d');
        $created = (string) ($risk['date_creation'] ?? '');

        $rows = [];
        foreach ($actions as $action) {
            $rows[] = [
                'due_date'        => isset($action['due_date']) ? (string) $action['due_date'] : null,
                'completion_date' => isset($action['completion_date']) ? (string) $action['completion_date'] : null,
                'status'          => (string) ($action['status'] ?? ''),
            ];
        }

        return Rules::build(
            $start,
            $target,
            $rows,
            preg_match('/^\d{4}-\d{2}-\d{2}/', $created) === 1 ? substr($created, 0, 10) : $today,
            $today
        );
    }

    /**
     * Carte « Trajectoire du registre » de la cartographie : risques traités (réduire ou transférer,
     * non clos) des entités de la session.
     *
     * @return array<string, mixed>
     */
    public static function registerData(): array
    {
        global $DB;

        $table = Risk::getTable();
        $risks = [];
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => [
                    'treatment' => TreatmentPlanRules::TREATMENTS_REQUIRING_A_PLAN,
                    'NOT'       => ['status' => 'closed'],
                ] + EntityPerimeter::criteria($table),
                'ORDER' => 'title',
            ]) as $row
        ) {
            $risks[(int) $row['id']] = $row;
        }

        $actionsByRisk = [];
        if ($risks !== []) {
            foreach (
                $DB->request([
                    'FROM'  => RiskTreatmentAction::getTable(),
                    'WHERE' => ['plugin_grcmanager_risks_id' => array_keys($risks)],
                    'ORDER' => 'due_date ASC, id ASC',
                ]) as $action
            ) {
                $actionsByRisk[(int) $action['plugin_grcmanager_risks_id']][] = $action;
            }
        }

        $rows = [];
        $charted = [];
        $counts = array_fill_keys(
            [Rules::STATE_LATE, Rules::STATE_ON_TRACK, Rules::STATE_AHEAD, Rules::STATE_DONE, 'incomplete'],
            0
        );
        foreach ($risks as $id => $risk) {
            $trajectory = self::forRisk($risk, $actionsByRisk[$id] ?? []);
            $isCharted = in_array($trajectory['state'], Rules::CHARTED_STATES, true);
            if ($isCharted) {
                $charted[] = $trajectory;
                $counts[$trajectory['state']]++;
            } else {
                $counts['incomplete']++;
            }
            $rows[] = [
                'name'          => (string) $risk['title'],
                'url'           => Risk::getFormURLWithID($id) . '&forcetab=' . rawurlencode(self::class . '$1'),
                'current_level' => self::levelBadge((string) $risk['risk_level']),
                'target_level'  => self::levelBadge((string) ($risk['residual_level'] ?? '')),
                'state'         => self::stateStyle($trajectory['state']),
                'done'          => $trajectory['done'],
                'total'         => $trajectory['total'],
                'late'          => $trajectory['late'],
                'planned_end'   => $trajectory['planned_end'] !== null
                    ? Html::convDate($trajectory['planned_end']) : '',
                // Plans en retard d'abord, puis dans les temps, en avance, et le reste.
                'sort'          => self::SORT_ORDER[$trajectory['state']] ?? count(self::SORT_ORDER),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $register = Rules::aggregate($charted, date('Y-m-d'));

        return [
            'rows'   => $rows,
            'counts' => $counts,
            'chart'  => $charted === [] ? null : self::chartData(
                $register['planned'],
                $register['actual'],
                round(array_sum(array_map(static fn(array $t): float => (float) $t['target'], $charted)), 2)
            ),
        ];
    }

    /**
     * Données du graphique (echarts, déjà fourni par GLPI) : séries prévue et réelle, date du jour.
     *
     * @param list<array{0: string, 1: float}> $planned
     * @param list<array{0: string, 1: float}> $actual
     * @param float|null $target score visé après traitement (ligne horizontale)
     *
     * @return array<string, mixed>
     */
    private static function chartData(array $planned, array $actual, ?float $target): array
    {
        return [
            'planned'       => $planned,
            'actual'        => $actual,
            'target'        => $target,
            'target_label'  => __('Target level after treatment', 'grcmanager'),
            'today'         => date('Y-m-d'),
            'planned_label' => __('Planned (treatment plan due dates)', 'grcmanager'),
            'actual_label'  => __('Actual (actions completed)', 'grcmanager'),
            'today_label'   => __('Today', 'grcmanager'),
            'score_label'   => __('Risk score (probability x impact)', 'grcmanager'),
        ];
    }

    /**
     * Badge d'un niveau de risque (mêmes couleurs que Risk::riskLevelBadge()), rendu par
     * _badge.html.twig ; null si le niveau n'est pas évalué.
     *
     * @return array{class: string, icon: string|null, label: string}|null
     */
    public static function levelBadge(string $level): ?array
    {
        $labels = Risk::getRiskLevels();
        if (!isset($labels[$level])) {
            return null;
        }

        return [
            'class' => [
                'low'      => 'bg-green-lt',
                'medium'   => 'bg-yellow-lt',
                'high'     => 'bg-orange-lt',
                'critical' => 'bg-red-lt',
            ][$level] ?? 'bg-secondary-lt',
            'icon'  => in_array($level, ['high', 'critical'], true) ? 'ti-alert-triangle' : null,
            'label' => $labels[$level],
        ];
    }

    /**
     * @return array{class: string, icon: string, label: string, help: string}
     */
    public static function stateStyle(string $state): array
    {
        return match ($state) {
            Rules::STATE_LATE => [
                'class' => 'bg-red-subtle text-red-emphasis',
                'icon'  => 'ti-clock-exclamation',
                'label' => __('Behind schedule', 'grcmanager'),
                'help'  => __(
                    'Some actions are past their due date: the risk is higher than planned today.',
                    'grcmanager'
                ),
            ],
            Rules::STATE_AHEAD => [
                'class' => 'bg-azure-subtle text-azure-emphasis',
                'icon'  => 'ti-rocket',
                'label' => __('Ahead of schedule', 'grcmanager'),
                'help'  => __(
                    'Actions were completed before their due date: the risk is lower than planned today.',
                    'grcmanager'
                ),
            ],
            Rules::STATE_ON_TRACK => [
                'class' => 'bg-green-subtle text-green-emphasis',
                'icon'  => 'ti-circle-check',
                'label' => __('On schedule', 'grcmanager'),
                'help'  => __('The actions due so far are completed.', 'grcmanager'),
            ],
            Rules::STATE_DONE => [
                'class' => 'bg-green-subtle text-green-emphasis',
                'icon'  => 'ti-checks',
                'label' => __('Plan completed', 'grcmanager'),
                'help'  => __(
                    'Every action of the treatment plan is completed: reassess the risk to confirm the residual level.',
                    'grcmanager'
                ),
            ],
            Rules::STATE_NO_TARGET => [
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-target-off',
                'label' => __('Target level missing', 'grcmanager'),
                'help'  => __(
                    'Fill in the residual probability and impact (the level aimed for after treatment) '
                        . 'on the risk form.',
                    'grcmanager'
                ),
            ],
            Rules::STATE_NO_REDUCTION => [
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-equal',
                'label' => __('No reduction planned', 'grcmanager'),
                'help'  => __(
                    'The target level is not lower than the current level: there is no trajectory to draw.',
                    'grcmanager'
                ),
            ],
            default => [
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-list-check',
                'label' => __('No treatment action', 'grcmanager'),
                'help'  => __(
                    'Add the actions of the treatment plan, with a due date, on the risk form.',
                    'grcmanager'
                ),
            ],
        };
    }

    private static function heatmapUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/riskheatmap.php#grc-risk-trajectory-register';
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #871 : trajectoire projetée d'un risque (clauses 6.1.3 et 8.3), calculée sur les échéances
 * de son plan de traitement, et comparée à ce qui est réellement fait.
 *
 * Modèle volontairement simple et explicable à un auditeur : le score part du risque initial
 * (probabilité x impact) et descend jusqu'au risque résiduel visé ; chaque action du plan porte une
 * part égale de cette baisse. La courbe prévue baisse à l'échéance de chaque action, la courbe réelle
 * à la date où l'action a été terminée. Une action sans échéance ne figure pas sur la courbe prévue
 * (le plan n'atteint alors pas le niveau visé, ce qui est signalé).
 *
 * Aucune base ni fonction GLPI : testé seul (tests/Unit/Services/Risk/RiskTrajectoryRulesTest.php).
 */
final class RiskTrajectoryRules
{
    public const STATE_NO_TARGET    = 'no_target';
    public const STATE_NO_REDUCTION = 'no_reduction';
    public const STATE_NO_ACTIONS   = 'no_actions';
    public const STATE_DONE         = 'done';
    public const STATE_LATE         = 'late';
    public const STATE_AHEAD        = 'ahead';
    public const STATE_ON_TRACK     = 'on_track';

    /** États où une courbe est tracée. */
    public const CHARTED_STATES = [self::STATE_DONE, self::STATE_LATE, self::STATE_AHEAD, self::STATE_ON_TRACK];

    private const EPSILON = 0.001;

    /**
     * @param float|null $target score du risque résiduel visé, null s'il n'est pas évalué
     * @param list<array{due_date?: ?string, completion_date?: ?string, status?: ?string}> $actions
     * @param string $startDate date (Y-m-d) de départ de la trajectoire (création du risque)
     * @param string $today date du jour (Y-m-d)
     *
     * @return array{
     *     state: string,
     *     start: float,
     *     target: float|null,
     *     step: float,
     *     total: int,
     *     done: int,
     *     late: int,
     *     unscheduled: int,
     *     planned_end: string|null,
     *     planned_today: float,
     *     actual_today: float,
     *     planned: list<array{0: string, 1: float}>,
     *     actual: list<array{0: string, 1: float}>,
     *     milestones: list<array{date: string, count: int, done: int, late: int, score: float}>
     * }
     */
    public static function build(float $start, ?float $target, array $actions, string $startDate, string $today): array
    {
        $result = [
            'state'         => self::STATE_ON_TRACK,
            'start'         => round($start, 2),
            'target'        => $target === null ? null : round($target, 2),
            'step'          => 0.0,
            'total'         => count($actions),
            'done'          => 0,
            'late'          => 0,
            'unscheduled'   => 0,
            'planned_end'   => null,
            'planned_today' => round($start, 2),
            'actual_today'  => round($start, 2),
            'planned'       => [],
            'actual'        => [],
            'milestones'    => [],
        ];

        $dueDates = [];
        $doneDates = [];
        $milestones = [];
        foreach ($actions as $action) {
            $due = self::date($action['due_date'] ?? null);
            $isDone = ($action['status'] ?? '') === TreatmentPlanRules::STATUS_DONE;
            if ($isDone) {
                $result['done']++;
                // Une action terminée sans date de fin (données anciennes) compte à son échéance,
                // à défaut aujourd'hui ; une date future est ramenée à aujourd'hui.
                $doneOn = self::date($action['completion_date'] ?? null) ?? $due ?? $today;
                $doneDates[] = min($doneOn, $today);
            }
            if ($due === null) {
                $result['unscheduled']++;
                continue;
            }
            $dueDates[] = $due;
            $late = !$isDone && $due < $today;
            if ($late) {
                $result['late']++;
            }
            $milestones[$due] ??= ['date' => $due, 'count' => 0, 'done' => 0, 'late' => 0, 'score' => 0.0];
            $milestones[$due]['count']++;
            $milestones[$due]['done'] += $isDone ? 1 : 0;
            $milestones[$due]['late'] += $late ? 1 : 0;
        }

        if ($target === null) {
            $result['state'] = self::STATE_NO_TARGET;

            return $result;
        }
        if ($target >= $start - self::EPSILON) {
            $result['state'] = self::STATE_NO_REDUCTION;

            return $result;
        }
        if ($actions === []) {
            $result['state'] = self::STATE_NO_ACTIONS;

            return $result;
        }

        $step = ($start - $target) / count($actions);
        $result['step'] = round($step, 4);
        sort($dueDates);
        sort($doneDates);
        $result['planned_end'] = $dueDates === [] ? null : end($dueDates);

        $origin = min(array_merge([$startDate, $today], $dueDates, $doneDates));
        $result['planned'] = self::steps($origin, $start, $step, $dueDates);
        $result['actual'] = self::steps($origin, $start, $step, $doneDates);
        // La courbe réelle s'arrête aujourd'hui ; la prévue va au moins jusqu'à aujourd'hui.
        $result['actual'][] = [$today, self::valueAt($result['actual'], $today)];
        $lastPlanned = end($result['planned']);
        if ($lastPlanned !== false && $lastPlanned[0] < $today) {
            $result['planned'][] = [$today, $lastPlanned[1]];
        }
        $result['actual'] = self::dedupe($result['actual']);
        $result['planned'] = self::dedupe($result['planned']);

        $result['planned_today'] = self::valueAt($result['planned'], $today);
        $result['actual_today'] = self::valueAt($result['actual'], $today);

        ksort($milestones);
        $reached = 0;
        foreach ($milestones as $date => $milestone) {
            $reached += $milestone['count'];
            $milestones[$date]['score'] = round($start - $step * $reached, 2);
        }
        $result['milestones'] = array_values($milestones);

        $result['state'] = match (true) {
            $result['done'] === $result['total']                                   => self::STATE_DONE,
            $result['actual_today'] > $result['planned_today'] + self::EPSILON     => self::STATE_LATE,
            $result['late'] > 0                                                    => self::STATE_LATE,
            $result['actual_today'] < $result['planned_today'] - self::EPSILON     => self::STATE_AHEAD,
            default                                                                => self::STATE_ON_TRACK,
        };

        return $result;
    }

    /**
     * Valeur d'une courbe en escalier à une date : celle du dernier point à cette date ou avant,
     * celle du premier point avant le début.
     *
     * @param list<array{0: string, 1: float}> $points triés par date
     */
    public static function valueAt(array $points, string $date): float
    {
        if ($points === []) {
            return 0.0;
        }
        $value = $points[0][1];
        foreach ($points as [$pointDate, $score]) {
            if ($pointDate > $date) {
                break;
            }
            $value = $score;
        }

        return $value;
    }

    /**
     * Trajectoire du registre : somme, à chaque date, des scores des risques dont la trajectoire est
     * tracée. La courbe réelle s'arrête aujourd'hui.
     *
     * @param list<array{planned: list<array{0: string, 1: float}>, actual: list<array{0: string, 1: float}>}>
     *        $trajectories
     *
     * @return array{planned: list<array{0: string, 1: float}>, actual: list<array{0: string, 1: float}>}
     */
    public static function aggregate(array $trajectories, string $today): array
    {
        $dates = [$today => true];
        foreach ($trajectories as $trajectory) {
            foreach (array_merge($trajectory['planned'], $trajectory['actual']) as [$date]) {
                $dates[$date] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);

        $planned = [];
        $actual = [];
        foreach ($dates as $date) {
            $plannedSum = 0.0;
            $actualSum = 0.0;
            foreach ($trajectories as $trajectory) {
                $plannedSum += self::valueAt($trajectory['planned'], $date);
                $actualSum += self::valueAt($trajectory['actual'], $date);
            }
            $planned[] = [$date, round($plannedSum, 2)];
            if ($date <= $today) {
                $actual[] = [$date, round($actualSum, 2)];
            }
        }

        return ['planned' => $planned, 'actual' => $actual];
    }

    /**
     * @param list<string> $dates triées
     *
     * @return list<array{0: string, 1: float}>
     */
    private static function steps(string $origin, float $start, float $step, array $dates): array
    {
        $points = [[$origin, round($start, 2)]];
        $count = 0;
        foreach ($dates as $date) {
            $count++;
            $points[] = [$date, round($start - $step * $count, 2)];
        }

        return $points;
    }

    /**
     * Un seul point par date (le dernier, après toutes les baisses du jour).
     *
     * @param list<array{0: string, 1: float}> $points
     *
     * @return list<array{0: string, 1: float}>
     */
    private static function dedupe(array $points): array
    {
        $byDate = [];
        foreach ($points as $point) {
            $byDate[$point[0]] = $point;
        }

        return array_values($byDate);
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 10);
    }
}

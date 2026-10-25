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

namespace GlpiPlugin\Grcmanager\Services\Exercise;

/**
 * Issue #168 : exercices de crise (test des plans de continuité, A.5.29 / A.5.30). Scénarios
 * prêts à l'emploi (étapes et délai visé en minutes depuis le début) et calcul des temps de
 * réaction, sans dépendance à GLPI.
 */
final class CrisisExercise
{
    public const STEP_ON_TIME = 'on_time';
    public const STEP_LATE = 'late';
    public const STEP_PENDING = 'pending';
    public const STEP_MISSED = 'missed';

    /**
     * Étapes de chaque scénario : [clé, délai visé en minutes depuis le début de l'exercice].
     *
     * @var array<string, array{category: string, severity: string, steps: list<array{string, int}>}>
     */
    public const SCENARIOS = [
        'ransomware'  => [
            'category' => 'malware',
            'severity' => 'critical',
            'steps'    => [
                ['confirm_alert', 15],
                ['isolate_network', 30],
                ['crisis_cell', 45],
                ['inform_management', 60],
                ['assess_scope', 120],
                ['restore_test_backup', 240],
                ['communicate', 180],
                ['back_to_normal', 480],
            ],
        ],
        'data_breach' => [
            'category' => 'data_breach',
            'severity' => 'major',
            'steps'    => [
                ['confirm_alert', 30],
                ['contain_leak', 60],
                ['crisis_cell', 60],
                ['identify_data', 120],
                ['inform_dpo', 180],
                ['regulator_decision', 240],
                ['communicate', 360],
            ],
        ],
        'outage'      => [
            'category' => 'availability',
            'severity' => 'major',
            'steps'    => [
                ['confirm_alert', 15],
                ['crisis_cell', 30],
                ['activate_continuity_plan', 45],
                ['inform_users', 60],
                ['switch_to_backup', 180],
                ['back_to_normal', 480],
            ],
        ],
    ];

    /**
     * Résultat d'une étape : faite dans le délai, en retard, à faire ou manquée (exercice terminé
     * sans qu'elle soit faite).
     */
    public static function stepStatus(?int $minutes, int $target, bool $ended): string
    {
        if ($minutes === null) {
            return $ended ? self::STEP_MISSED : self::STEP_PENDING;
        }

        return $minutes <= $target ? self::STEP_ON_TIME : self::STEP_LATE;
    }

    /**
     * Minutes écoulées depuis le début de l'exercice, null si l'étape n'est pas faite.
     */
    public static function minutesSince(string $startedAt, ?string $doneAt): ?int
    {
        if ($doneAt === null || $doneAt === '') {
            return null;
        }
        $start = strtotime($startedAt);
        $done = strtotime($doneAt);
        if ($start === false || $done === false) {
            return null;
        }

        return max(0, intdiv($done - $start, 60));
    }

    /**
     * Synthèse : étapes faites, dans le délai, temps de réaction moyen (minutes) et taux de
     * réussite (étapes faites dans le délai / étapes du scénario).
     *
     * @param list<array{minutes: ?int, status: string}> $steps
     * @return array{total: int, done: int, on_time: int, average: ?int, rate: int}
     */
    public static function summary(array $steps): array
    {
        $done = array_values(array_filter($steps, static fn (array $s): bool => $s['minutes'] !== null));
        $onTime = count(array_filter($steps, static fn (array $s): bool => $s['status'] === self::STEP_ON_TIME));
        $total = count($steps);

        return [
            'total'   => $total,
            'done'    => count($done),
            'on_time' => $onTime,
            'average' => $done === []
                ? null
                : (int) round(array_sum(array_column($done, 'minutes')) / count($done)),
            'rate'    => $total === 0 ? 0 : (int) round(100 * $onTime / $total),
        ];
    }

    /**
     * Étapes dans l'ordre de leur délai visé (ordre d'affichage de la salle de crise).
     *
     * @return list<array{string, int}>
     */
    public static function orderedSteps(string $scenario): array
    {
        $steps = self::SCENARIOS[$scenario]['steps'] ?? [];
        usort($steps, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $steps;
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

/**
 * Issue #776 : règles des scénarios de crise sécurité de l'information (A.5.29), sans base ni
 * session : processus critique, état d'exercice, manques d'un scénario, couverture.
 */
final class CrisisScenarioRules
{
    /** Un processus est critique quand son RTO est défini et ne dépasse pas 24 heures. */
    public const CRITICAL_RTO_HOURS = 24;

    /** Un scénario exercé il y a plus d'un an est à exercer de nouveau. */
    public const EXERCISE_VALIDITY_DAYS = 365;

    public const EXERCISE_NEVER = 'never';
    public const EXERCISE_OVERDUE = 'overdue';
    public const EXERCISE_OK = 'ok';

    public const GAP_NO_PROCESS = 'no_process';
    public const GAP_NO_PLAYBOOK = 'no_playbook';
    public const GAP_NO_DEGRADED_MODE = 'no_degraded_mode';
    public const GAP_NO_ROLES = 'no_roles';
    public const GAP_NEVER_EXERCISED = 'never_exercised';
    public const GAP_EXERCISE_OVERDUE = 'exercise_overdue';

    public const ROLE_FIELDS = ['role_management', 'role_ciso', 'role_it'];

    /**
     * @param array<string, mixed> $process
     */
    public static function isCritical(array $process): bool
    {
        $rto = (int) ($process['rto_hours'] ?? 0);

        return $rto > 0 && $rto <= self::CRITICAL_RTO_HOURS;
    }

    /**
     * Date la plus récente parmi celles données (AAAA-MM-JJ, éventuellement suivie de l'heure),
     * null si aucune.
     */
    public static function latestDate(?string ...$dates): ?string
    {
        $latest = null;
        foreach ($dates as $date) {
            $day = $date === null ? '' : substr(trim($date), 0, 10);
            if (
                preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) !== 1
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ) {
                continue;
            }
            if ($latest === null || strcmp($day, $latest) > 0) {
                $latest = $day;
            }
        }

        return $latest;
    }

    public static function exerciseStatus(?string $lastExercise, string $today): string
    {
        $last = self::latestDate($lastExercise);
        if ($last === null) {
            return self::EXERCISE_NEVER;
        }
        $limit = date('Y-m-d', (int) strtotime($today . ' -' . self::EXERCISE_VALIDITY_DAYS . ' days'));

        return strcmp($last, $limit) < 0 ? self::EXERCISE_OVERDUE : self::EXERCISE_OK;
    }

    /**
     * Manques d'un scénario actif, dans l'ordre d'affichage.
     *
     * @param array<string, mixed> $scenario
     * @return list<string>
     */
    public static function gaps(array $scenario, int $processCount, bool $hasPlaybook, string $exerciseStatus): array
    {
        $gaps = [];
        if ($processCount === 0) {
            $gaps[] = self::GAP_NO_PROCESS;
        }
        if (trim((string) ($scenario['degraded_mode'] ?? '')) === '') {
            $gaps[] = self::GAP_NO_DEGRADED_MODE;
        }
        foreach (self::ROLE_FIELDS as $field) {
            if (trim((string) ($scenario[$field] ?? '')) === '') {
                $gaps[] = self::GAP_NO_ROLES;
                break;
            }
        }
        if (!$hasPlaybook) {
            $gaps[] = self::GAP_NO_PLAYBOOK;
        }
        if ($exerciseStatus === self::EXERCISE_NEVER) {
            $gaps[] = self::GAP_NEVER_EXERCISED;
        } elseif ($exerciseStatus === self::EXERCISE_OVERDUE) {
            $gaps[] = self::GAP_EXERCISE_OVERDUE;
        }

        return $gaps;
    }

    /**
     * Processus critiques sans aucun scénario actif.
     *
     * @param list<array<string, mixed>> $processes processus lisibles (id, rto_hours...)
     * @param array<int, int> $activeScenarioCount nombre de scénarios actifs par processus
     * @return list<array<string, mixed>>
     */
    public static function uncoveredCriticalProcesses(array $processes, array $activeScenarioCount): array
    {
        return array_values(array_filter(
            $processes,
            static fn (array $process): bool => self::isCritical($process)
                && ($activeScenarioCount[(int) ($process['id'] ?? 0)] ?? 0) === 0
        ));
    }

    /**
     * Synthèse de la couverture : processus critiques, ceux sans scénario, scénarios actifs, jamais
     * exercés ou à exercer de nouveau, scénarios par défaut encore absents.
     *
     * @param list<array<string, mixed>> $processes
     * @param array<int, int> $activeScenarioCount
     * @param list<array{is_active: bool, exercise_status: string, gaps: list<string>, default_key: string}> $scenarios
     * @return array{critical: int, critical_uncovered: int, scenarios: int, never_exercised: int, overdue: int,
     *     with_gaps: int, missing_defaults: list<string>}
     */
    public static function summary(array $processes, array $activeScenarioCount, array $scenarios): array
    {
        $active = array_values(array_filter($scenarios, static fn (array $s): bool => $s['is_active']));
        $keys = array_column($scenarios, 'default_key');

        return [
            'critical'           => count(array_filter($processes, [self::class, 'isCritical'])),
            'critical_uncovered' => count(self::uncoveredCriticalProcesses($processes, $activeScenarioCount)),
            'scenarios'          => count($active),
            'never_exercised'    => count(array_filter(
                $active,
                static fn (array $s): bool => $s['exercise_status'] === self::EXERCISE_NEVER
            )),
            'overdue'            => count(array_filter(
                $active,
                static fn (array $s): bool => $s['exercise_status'] === self::EXERCISE_OVERDUE
            )),
            'with_gaps'          => count(array_filter($active, static fn (array $s): bool => $s['gaps'] !== [])),
            'missing_defaults'   => array_values(array_diff(CrisisScenarioDefaults::KEYS, $keys)),
        ];
    }

    /**
     * Un processus peut-il être lié au scénario ? Même entité ; ou processus d'une sous-entité d'un
     * scénario récursif ; ou processus récursif d'une entité parente du scénario.
     *
     * @param list<int> $scenarioAncestors entités parentes de celle du scénario
     * @param list<int> $processAncestors entités parentes de celle du processus
     */
    public static function processInScope(
        int $processEntity,
        bool $processRecursive,
        int $scenarioEntity,
        bool $scenarioRecursive,
        array $scenarioAncestors,
        array $processAncestors
    ): bool {
        if ($processEntity === $scenarioEntity) {
            return true;
        }
        if ($scenarioRecursive && in_array($scenarioEntity, $processAncestors, true)) {
            return true;
        }

        return $processRecursive && in_array($processEntity, $scenarioAncestors, true);
    }
}

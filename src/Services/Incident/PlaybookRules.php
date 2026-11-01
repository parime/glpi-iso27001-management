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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #370 : procédures de remédiation (playbooks). Phases des étapes, statuts des tâches,
 * avancement et blocage de la clôture. Sans base : testable seul.
 */
final class PlaybookRules
{
    public const PHASES = ['containment', 'analysis', 'notification', 'eradication', 'recovery', 'post_incident'];

    public const STATUSES = ['todo', 'in_progress', 'done', 'not_applicable'];

    /** Statuts qui terminent une tâche. */
    public const FINISHED = ['done', 'not_applicable'];

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array{done: int, total: int, percent: int, mandatory_pending: int}
     */
    public static function progress(array $tasks): array
    {
        $done = 0;
        $pending = 0;
        foreach ($tasks as $task) {
            $finished = in_array((string) ($task['status'] ?? ''), self::FINISHED, true);
            $done += $finished ? 1 : 0;
            if (!$finished && !empty($task['is_mandatory'])) {
                $pending++;
            }
        }
        $total = count($tasks);

        return [
            'done'              => $done,
            'total'             => $total,
            'percent'           => $total === 0 ? 0 : (int) floor($done * 100 / $total),
            'mandatory_pending' => $pending,
        ];
    }

    /**
     * La clôture est refusée tant qu'une étape obligatoire n'est ni faite ni « non applicable ».
     *
     * @param list<array<string, mixed>> $tasks
     */
    public static function blocksClosure(array $tasks): bool
    {
        return self::progress($tasks)['mandatory_pending'] > 0;
    }

    /**
     * Tâches regroupées par phase, dans l'ordre des phases puis du rang.
     *
     * @param list<array<string, mixed>> $tasks
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byPhase(array $tasks): array
    {
        $groups = array_fill_keys(self::PHASES, []);
        usort($tasks, static fn (array $a, array $b): int => (int) ($a['rank'] ?? 0) <=> (int) ($b['rank'] ?? 0));
        foreach ($tasks as $task) {
            $phase = in_array($task['phase'] ?? '', self::PHASES, true) ? (string) $task['phase'] : 'post_incident';
            $groups[$phase][] = $task;
        }

        return array_filter($groups, static fn (array $group): bool => $group !== []);
    }

    /**
     * Procédures proposées pour un incident : celles de sa catégorie d'abord.
     *
     * @param list<array{id: int, name: string, category: string}> $playbooks
     * @return list<array{id: int, name: string, category: string, recommended: bool}>
     */
    public static function suggest(array $playbooks, string $incidentCategory): array
    {
        $suggested = array_map(static fn (array $playbook): array => $playbook + [
            'recommended' => $playbook['category'] !== '' && $playbook['category'] === $incidentCategory,
        ], $playbooks);
        $key = static fn (array $playbook): array => [!$playbook['recommended'], $playbook['name']];
        usort($suggested, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $suggested;
    }
}

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
 * Issue #169 : règles du rapport post-incident (enseignements tirés, A.5.27).
 */
final class PostMortem
{
    public const DEADLINE_MET = 'met';

    public const DEADLINE_LATE = 'late';

    public const DEADLINE_MISSING = 'missing';

    /**
     * Une échéance réglementaire a-t-elle été tenue ? « Non notifiée » si aucune date n'est saisie.
     */
    public static function deadlineOutcome(?string $doneAt, string $due): string
    {
        if ($doneAt === null || $doneAt === '') {
            return self::DEADLINE_MISSING;
        }

        return strtotime($doneAt) <= strtotime($due) ? self::DEADLINE_MET : self::DEADLINE_LATE;
    }

    /**
     * Coût total, même formule que les coûts natifs de GLPI : temps passé × coût horaire, plus
     * coûts fixe et matériel.
     *
     * @param iterable<array<string, mixed>> $costs
     */
    public static function costTotal(iterable $costs): float
    {
        $total = 0.0;
        foreach ($costs as $cost) {
            $total += ((int) ($cost['actiontime'] ?? 0)) / 3600 * (float) ($cost['cost_time'] ?? 0)
                + (float) ($cost['cost_fixed'] ?? 0)
                + (float) ($cost['cost_material'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Chronologie triée par date ; à date égale, l'ordre d'origine est conservé.
     *
     * @param list<array{date: string}&array<string, mixed>> $events
     * @return list<array{date: string}&array<string, mixed>>
     */
    public static function sortTimeline(array $events): array
    {
        $indexed = [];
        foreach ($events as $position => $event) {
            $indexed[] = [(string) $event['date'], $position, $event];
        }
        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($indexed, 2);
    }

    /**
     * Durée entre deux dates, en heures (null si l'une manque).
     */
    public static function hoursBetween(?string $from, ?string $to): ?float
    {
        if ($from === null || $from === '' || $to === null || $to === '') {
            return null;
        }

        return round((strtotime($to) - strtotime($from)) / 3600, 1);
    }
}

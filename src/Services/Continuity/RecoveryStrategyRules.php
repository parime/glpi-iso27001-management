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
 * Issue #377, troisième étape : stratégie de reprise d'un processus métier (ISO 22301 8.3 et 8.5),
 * sans base. Un processus décrit son mode dégradé, sa solution de secours et sa procédure de
 * reprise ; chaque test (ou exercice de crise) mesure le temps de reprise réel, comparé au RTO.
 */
final class RecoveryStrategyRules
{
    /** Plus long temps de reprise accepté à la saisie : 60 jours, en minutes. */
    public const MAX_MINUTES = 86400;

    public static function sanitizeMinutes(mixed $value): int
    {
        $minutes = is_numeric($value) ? (int) $value : 0;

        return $minutes > 0 && $minutes <= self::MAX_MINUTES ? $minutes : 0;
    }

    /**
     * Date d'un test (AAAA-MM-JJ), ou null si elle n'est pas valide.
     */
    public static function sanitizeDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * Le test le plus récent (date, puis ordre de saisie), ou null.
     *
     * @param iterable<array<string, mixed>> $tests lignes avec id, test_date et measured_minutes
     * @return array<string, mixed>|null
     */
    public static function lastTest(iterable $tests): ?array
    {
        $last = null;
        foreach ($tests as $test) {
            $key = [(string) ($test['test_date'] ?? ''), (int) ($test['id'] ?? 0)];
            if ($last === null || $key > [(string) ($last['test_date'] ?? ''), (int) ($last['id'] ?? 0)]) {
                $last = $test;
            }
        }

        return $last;
    }

    /**
     * Le temps mesuré dépasse le RTO (en heures ; 0 : RTO non défini, rien à comparer).
     */
    public static function exceedsRto(int $rtoHours, int $measuredMinutes): bool
    {
        return $rtoHours > 0 && $measuredMinutes > $rtoHours * 60;
    }

    /**
     * Contrôles de la stratégie de reprise. Codes :
     * - strategy_missing : ni mode dégradé, ni solution de secours, ni procédure ;
     * - recovery_untested : aucun temps de reprise mesuré ;
     * - recovery_above_rto : le dernier temps mesuré dépasse le RTO du processus.
     *
     * @param list<string> $texts mode dégradé, solution de secours, procédure
     * @return list<string>
     */
    public static function strategyIssues(int $rtoHours, array $texts, ?int $lastMeasuredMinutes): array
    {
        $issues = [];
        if (array_filter($texts, static fn (string $text): bool => trim($text) !== '') === []) {
            $issues[] = 'strategy_missing';
        }
        if ($lastMeasuredMinutes === null) {
            $issues[] = 'recovery_untested';
        } elseif (self::exceedsRto($rtoHours, $lastMeasuredMinutes)) {
            $issues[] = 'recovery_above_rto';
        }

        return $issues;
    }

    /**
     * @return array{hours: int, minutes: int}
     */
    public static function split(int $minutes): array
    {
        $minutes = max(0, $minutes);

        return ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60];
    }
}

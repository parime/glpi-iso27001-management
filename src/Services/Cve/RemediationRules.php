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

namespace GlpiPlugin\Grcmanager\Services\Cve;

use DateTimeImmutable;

/**
 * Issue #601 : délais de correction des vulnérabilités du parc (ISO 27001 A.8.8, NIS2), règles
 * pures, sans base de données.
 *
 * Une vulnérabilité du parc est un couple CVE et logiciel du catalogue. Son niveau découle de ses
 * signaux : exploitée activement (CISA KEV), puis CVSS critique (9 et plus), élevé (7), moyen (4) ou
 * faible ; un score inconnu compte comme moyen. Chaque niveau a un délai de correction réglable,
 * raccourci d'un pourcentage quand une machine touchée porte une classification C/I/D élevée.
 *
 * Choix documentés :
 * - le délai court depuis la première détection par le plugin ; un niveau qui change (une CVE qui
 *   entre au catalogue KEV, par exemple) fait courir le délai du nouveau niveau depuis ce jour,
 *   sans jamais reculer l'échéance déjà connue ;
 * - une vulnérabilité qui disparaît du parc est « corrigée » ce jour-là. Si elle réapparaît dans
 *   les REOPEN_GRACE_DAYS jours, la correction est annulée (disparition passagère : inventaire
 *   incomplet, machine absente) et l'échéance d'origine reste ; au-delà, c'est une nouvelle
 *   exposition : la date de première détection est conservée, le délai repart de la réapparition ;
 * - une dérogation validée et en cours remplace l'échéance par sa date de fin ; échue, elle ne
 *   couvre plus rien et la vulnérabilité redevient en retard si son échéance est passée.
 *
 * Rien n'est modifié automatiquement : ces états servent à afficher et à mesurer.
 */
final class RemediationRules
{
    public const KEV = 'kev';
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** Niveaux, du plus urgent au moins urgent. */
    public const LEVELS = [self::KEV, self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];

    /** Délais proposés, en jours. */
    public const DEFAULT_DAYS = [
        self::KEV      => 7,
        self::CRITICAL => 15,
        self::HIGH     => 30,
        self::MEDIUM   => 90,
        self::LOW      => 180,
    ];

    public const MAX_DAYS = 3650;

    /** Délai appliqué aux actifs de classification C/I/D élevée, en pourcentage du délai normal. */
    public const DEFAULT_CRITICAL_PERCENT = 50;

    public const MIN_CRITICAL_PERCENT = 10;

    /** Réapparition dans ce délai après une correction : disparition passagère, la correction est annulée. */
    public const REOPEN_GRACE_DAYS = 30;

    /** Période des indicateurs de correction (taux dans les délais, délai moyen). */
    public const WINDOW_DAYS = 90;

    /** Mois de la tendance. */
    public const TREND_MONTHS = 6;

    public const STATUS_ON_TIME = 'on_time';
    public const STATUS_DUE_SOON = 'due_soon';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_FIXED = 'fixed';

    /**
     * Réglages bornés. La politique est désactivée tant que l'administrateur ne l'a pas activée.
     *
     * @param array<string, mixed> $values enabled, days (par niveau), critical_percent
     * @return array{enabled: bool, days: array<string, int>, critical_percent: int}
     */
    public static function settings(array $values): array
    {
        $days = [];
        $given = is_array($values['days'] ?? null) ? $values['days'] : [];
        foreach (self::DEFAULT_DAYS as $level => $default) {
            $value = $given[$level] ?? null;
            $days[$level] = is_numeric($value) && (int) $value >= 1
                ? min(self::MAX_DAYS, (int) $value)
                : $default;
        }
        $percent = $values['critical_percent'] ?? null;

        return [
            'enabled'          => (bool) ($values['enabled'] ?? false),
            'days'             => $days,
            'critical_percent' => is_numeric($percent)
                ? max(self::MIN_CRITICAL_PERCENT, min(100, (int) $percent))
                : self::DEFAULT_CRITICAL_PERCENT,
        ];
    }

    public static function level(?float $cvss, bool $isKev): string
    {
        return match (true) {
            $isKev                      => self::KEV,
            $cvss === null              => self::MEDIUM,
            $cvss >= 9.0                => self::CRITICAL,
            $cvss >= 7.0                => self::HIGH,
            $cvss >= 4.0                => self::MEDIUM,
            default                     => self::LOW,
        };
    }

    /**
     * Délai de correction, en jours, d'un niveau ; raccourci pour un actif critique (au moins un jour).
     *
     * @param array{days: array<string, int>, critical_percent: int} $settings
     */
    public static function deadline(string $level, bool $critical, array $settings): int
    {
        $days = $settings['days'][$level] ?? self::DEFAULT_DAYS[self::MEDIUM];

        return $critical ? max(1, (int) ceil($days * $settings['critical_percent'] / 100)) : $days;
    }

    /**
     * Échéance de correction : délai du niveau actuel depuis le jour où ce niveau a été constaté,
     * jamais après l'échéance connue avant un changement de niveau.
     *
     * @param array{level: string, is_critical: bool, date_level: string, previous_due: ?string} $record
     * @param array{days: array<string, int>, critical_percent: int} $settings
     */
    public static function dueDate(array $record, array $settings): string
    {
        $due = self::addDays(
            $record['date_level'],
            self::deadline($record['level'], $record['is_critical'], $settings)
        );
        $previous = $record['previous_due'] ?? null;

        return $previous !== null && $previous !== '' && $previous < $due ? $previous : $due;
    }

    /**
     * Suivi d'une vulnérabilité présente dans le parc aujourd'hui : création, réapparition ou
     * changement de niveau. Retourne l'enregistrement à jour.
     *
     * @param array<string, mixed>|null $record Enregistrement connu, null pour une première détection.
     * @param array{days: array<string, int>, critical_percent: int} $settings
     * @return array{date_first_seen: string, date_start: string, date_last_seen: string, date_fixed: ?string,
     *     level: string, is_critical: bool, date_level: string, previous_due: ?string}
     */
    public static function observe(?array $record, string $level, bool $critical, string $today, array $settings): array
    {
        if ($record === null) {
            return [
                'date_first_seen' => $today,
                'date_start'      => $today,
                'date_last_seen'  => $today,
                'date_fixed'      => null,
                'level'           => $level,
                'is_critical'     => $critical,
                'date_level'      => $today,
                'previous_due'    => null,
            ];
        }
        $next = [
            'date_first_seen' => (string) $record['date_first_seen'],
            'date_start'      => (string) $record['date_start'],
            'date_last_seen'  => $today,
            'date_fixed'      => null,
            'level'           => (string) $record['level'],
            'is_critical'     => (bool) $record['is_critical'],
            'date_level'      => (string) $record['date_level'],
            'previous_due'    => self::dateOrNull($record['previous_due'] ?? null),
        ];
        $fixed = self::dateOrNull($record['date_fixed'] ?? null);
        if ($fixed !== null && self::daysBetween($fixed, $today) > self::REOPEN_GRACE_DAYS) {
            // Nouvelle exposition : la première détection reste, le délai repart d'aujourd'hui.
            return [
                'date_start'   => $today,
                'level'        => $level,
                'is_critical'  => $critical,
                'date_level'   => $today,
                'previous_due' => null,
            ] + $next;
        }
        if ($next['level'] !== $level || $next['is_critical'] !== $critical) {
            $next['previous_due'] = self::dueDate($next, $settings);
            $next['level'] = $level;
            $next['is_critical'] = $critical;
            $next['date_level'] = $today;
        }

        return $next;
    }

    /**
     * État d'une vulnérabilité suivie.
     *
     * @param array{date_start: string, date_fixed: ?string, level: string, is_critical: bool,
     *     date_level: string, previous_due: ?string} $record
     * @param string|null $waiverEnd Fin de la dérogation validée la plus longue qui couvre la CVE.
     * @param array{days: array<string, int>, critical_percent: int} $settings
     * @return array{status: string, due: string, sla_due: string, days: int, deadline: int, level: string,
     *     on_time: ?bool}
     */
    public static function state(array $record, ?string $waiverEnd, string $today, array $settings): array
    {
        $slaDue = self::dueDate($record, $settings);
        $deadline = self::deadline($record['level'], $record['is_critical'], $settings);
        $waiverEnd = self::dateOrNull($waiverEnd);
        $base = ['sla_due' => $slaDue, 'deadline' => $deadline, 'level' => $record['level']];

        $fixed = self::dateOrNull($record['date_fixed'] ?? null);
        if ($fixed !== null) {
            $due = $waiverEnd !== null && $waiverEnd > $slaDue ? $waiverEnd : $slaDue;

            return [
                'status'  => self::STATUS_FIXED,
                'due'     => $due,
                'days'    => self::daysBetween((string) $record['date_start'], $fixed),
                'on_time' => $fixed <= $due,
            ] + $base;
        }
        if ($waiverEnd !== null && $waiverEnd >= $today) {
            return [
                'status'  => self::STATUS_WAIVED,
                'due'     => $waiverEnd,
                'days'    => self::daysBetween($today, $waiverEnd),
                'on_time' => null,
            ] + $base;
        }
        $left = self::daysBetween($today, $slaDue);
        $status = match (true) {
            $left < 0                          => self::STATUS_OVERDUE,
            $left <= self::soonDays($deadline) => self::STATUS_DUE_SOON,
            default                            => self::STATUS_ON_TIME,
        };

        return ['status' => $status, 'due' => $slaDue, 'days' => $left, 'on_time' => null] + $base;
    }

    /** Échéance « proche » : le dernier quart du délai, une semaine au plus, un jour au moins. */
    public static function soonDays(int $deadline): int
    {
        return max(1, min(7, intdiv($deadline, 4)));
    }

    /**
     * Indicateurs de respect des délais.
     *
     * - compliance : part des vulnérabilités dans les délais, parmi celles ouvertes et celles
     *   corrigées sur la période (une vulnérabilité couverte par une dérogation en cours est dans
     *   les délais) ;
     * - rate : part des corrections de la période faites dans les délais, previous_rate : idem sur
     *   la période précédente (tendance) ;
     * - mttr : délai moyen de correction, en jours, sur la période ;
     * - oldest_overdue, average_overdue : ancienneté des retards, en jours après l'échéance.
     *
     * @param list<array{status: string, due: string, days: int, level: string, on_time: ?bool,
     *     date_fixed: ?string}> $states
     * @param array{days: array<string, int>, critical_percent: int} $settings
     * @return array<string, mixed>
     */
    public static function indicators(array $states, string $today, array $settings): array
    {
        $windowStart = self::addDays($today, -self::WINDOW_DAYS);
        $previousStart = self::addDays($today, -2 * self::WINDOW_DAYS);
        $byLevel = [];
        foreach (self::LEVELS as $level) {
            $byLevel[$level] = [
                'days' => $settings['days'][$level], 'open' => 0, 'overdue' => 0, 'fixed' => 0,
                'fixed_on_time' => 0, 'rate' => null,
            ];
        }
        $months = self::lastMonths($today, self::TREND_MONTHS);
        $trend = array_fill_keys($months, ['on_time' => 0, 'late' => 0]);
        $totals = ['open' => 0, 'overdue' => 0, 'due_soon' => 0, 'waived' => 0, 'fixed' => 0, 'fixed_on_time' => 0];
        $previous = ['fixed' => 0, 'fixed_on_time' => 0];
        $fixDays = [];
        $overdueDays = [];
        foreach ($states as $state) {
            $level = isset($byLevel[$state['level']]) ? $state['level'] : self::MEDIUM;
            if ($state['status'] === self::STATUS_FIXED) {
                $fixed = (string) $state['date_fixed'];
                $month = substr($fixed, 0, 7);
                if (isset($trend[$month])) {
                    $trend[$month][$state['on_time'] ? 'on_time' : 'late']++;
                }
                if ($fixed > $windowStart && $fixed <= $today) {
                    $totals['fixed']++;
                    $byLevel[$level]['fixed']++;
                    $fixDays[] = $state['days'];
                    if ($state['on_time']) {
                        $totals['fixed_on_time']++;
                        $byLevel[$level]['fixed_on_time']++;
                    }
                } elseif ($fixed > $previousStart && $fixed <= $windowStart) {
                    $previous['fixed']++;
                    $previous['fixed_on_time'] += $state['on_time'] ? 1 : 0;
                }
                continue;
            }
            $totals['open']++;
            $byLevel[$level]['open']++;
            if ($state['status'] === self::STATUS_OVERDUE) {
                $totals['overdue']++;
                $byLevel[$level]['overdue']++;
                $overdueDays[] = -$state['days'];
            } elseif ($state['status'] === self::STATUS_DUE_SOON) {
                $totals['due_soon']++;
            } elseif ($state['status'] === self::STATUS_WAIVED) {
                $totals['waived']++;
            }
        }
        foreach ($byLevel as $level => $counts) {
            $byLevel[$level]['rate'] = self::percent($counts['fixed_on_time'], $counts['fixed']);
        }
        $trendRows = [];
        foreach ($trend as $month => $counts) {
            $trendRows[] = ['month' => $month] + $counts;
        }

        return $totals + [
            'compliance'      => self::percent(
                $totals['open'] - $totals['overdue'] + $totals['fixed_on_time'],
                $totals['open'] + $totals['fixed']
            ),
            'rate'            => self::percent($totals['fixed_on_time'], $totals['fixed']),
            'previous_rate'   => self::percent($previous['fixed_on_time'], $previous['fixed']),
            'mttr'            => $fixDays === [] ? null : round(array_sum($fixDays) / count($fixDays), 1),
            'oldest_overdue'  => $overdueDays === [] ? null : max($overdueDays),
            'average_overdue' => $overdueDays === [] ? null : (int) round(array_sum($overdueDays) / count($overdueDays)),
            'window_days'     => self::WINDOW_DAYS,
            'by_level'        => $byLevel,
            'trend'           => $trendRows,
        ];
    }

    /**
     * Identifiants de CVE d'un texte libre (virgules, espaces, retours à la ligne), en majuscules,
     * sans doublon ; tout le reste est ignoré.
     *
     * @return list<string>
     */
    public static function parseCveIds(string $text, int $limit = 100): array
    {
        preg_match_all('/\bCVE-\d{4}-\d{4,7}\b/i', $text, $matches);

        return array_slice(array_values(array_unique(array_map('strtoupper', $matches[0]))), 0, $limit);
    }

    /**
     * @return list<string> Mois « Y-m », du plus ancien au mois en cours.
     */
    public static function lastMonths(string $today, int $count): array
    {
        $first = new DateTimeImmutable(substr($today, 0, 7) . '-01');
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = $first->modify('-' . $i . ' months')->format('Y-m');
        }

        return $months;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable(substr($date, 0, 10)))->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }

    /** Nombre de jours de $from à $to (négatif si $to est avant $from). */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable(substr($from, 0, 10));
        $b = new DateTimeImmutable(substr($to, 0, 10));

        return (int) $a->diff($b)->format('%r%a');
    }

    private static function percent(int $part, int $total): ?int
    {
        return $total > 0 ? (int) round(100 * $part / $total) : null;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}

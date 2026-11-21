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

namespace GlpiPlugin\Grcmanager\Services\Capa;

/**
 * Issue #870 : calculs du rapport du stock d'écarts (clauses 10.1 et 10.2), sans accès à la base :
 * origine, âge, retard, délai moyen de clôture, tendance mensuelle et efficacité vérifiée.
 *
 * Une ligne est un tableau normalisé : kind, severity, origin, entities_id, users_id, created
 * (Y-m-d), due (Y-m-d ou null), closed (Y-m-d ou null), verified (bool).
 */
final class GapStockRules
{
    public const KIND_NONCONFORMITY = 'nonconformity';
    public const KIND_FINDING = 'finding';
    public const KIND_REVIEW_ACTION = 'review_action';
    public const KINDS = [self::KIND_NONCONFORMITY, self::KIND_FINDING, self::KIND_REVIEW_ACTION];

    public const ORIGIN_INTERNAL_AUDIT = 'internal_audit';
    public const ORIGIN_CERTIFICATION_AUDIT = 'certification_audit';
    public const ORIGIN_INCIDENT = 'incident';
    public const ORIGIN_MANAGEMENT_REVIEW = 'management_review';
    public const ORIGIN_OTHER = 'other';
    public const ORIGINS = [
        self::ORIGIN_INTERNAL_AUDIT,
        self::ORIGIN_CERTIFICATION_AUDIT,
        self::ORIGIN_INCIDENT,
        self::ORIGIN_MANAGEMENT_REVIEW,
        self::ORIGIN_OTHER,
    ];

    /** Tranches d'âge des écarts ouverts, en jours (borne haute incluse). */
    public const AGE_BUCKETS = ['0-30' => 30, '31-90' => 90, '91-180' => 180, '180+' => PHP_INT_MAX];

    /** Période par défaut et période la plus longue, en mois. */
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 36;

    /**
     * Origine d'une non-conformité : type de son audit, sinon l'incident à son origine.
     */
    public static function origin(int $auditId, string $auditType, int $incidentId): string
    {
        if ($auditId > 0) {
            return $auditType === '' || $auditType === 'internal'
                ? self::ORIGIN_INTERNAL_AUDIT
                : self::ORIGIN_CERTIFICATION_AUDIT;
        }

        return $incidentId > 0 ? self::ORIGIN_INCIDENT : self::ORIGIN_OTHER;
    }

    public static function kindOf(string $findingType): string
    {
        return $findingType === 'nonconformity' ? self::KIND_NONCONFORMITY : self::KIND_FINDING;
    }

    /** Nombre de jours entre deux dates Y-m-d (0 si la fin précède le début). */
    public static function days(string $from, string $to): int
    {
        $start = strtotime(substr($from, 0, 10) . ' 00:00:00 UTC');
        $end = strtotime(substr($to, 0, 10) . ' 00:00:00 UTC');
        if ($start === false || $end === false || $end < $start) {
            return 0;
        }

        return intdiv($end - $start, 86400);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isOpen(array $row, string $at): bool
    {
        return $row['created'] <= $at && ($row['closed'] === null || $row['closed'] > $at);
    }

    /**
     * Jours de retard d'un écart ouvert (0 sans échéance ou dans les délais).
     *
     * @param array<string, mixed> $row
     */
    public static function overdueDays(array $row, string $today): int
    {
        if ($row['closed'] !== null || $row['due'] === null || $row['due'] >= $today) {
            return 0;
        }

        return self::days((string) $row['due'], $today);
    }

    public static function ageBucket(int $days): string
    {
        foreach (self::AGE_BUCKETS as $bucket => $max) {
            if ($days <= $max) {
                return $bucket;
            }
        }

        return '180+';
    }

    /**
     * Période normalisée : mois de début et de fin (Y-m), au plus MAX_MONTHS mois, par défaut les
     * DEFAULT_MONTHS derniers mois ; jamais après le mois en cours.
     *
     * @return array{from: string, to: string}
     */
    public static function period(mixed $from, mixed $to, string $today): array
    {
        $valid = static fn (mixed $m): bool => is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1;
        $current = substr($today, 0, 7);
        $end = $valid($to) ? (string) $to : $current;
        if ($end > $current) {
            $end = $current;
        }
        $start = $valid($from) ? (string) $from : self::shift($end, -(self::DEFAULT_MONTHS - 1));
        if ($start > $end) {
            $start = $end;
        }
        $earliest = self::shift($end, -(self::MAX_MONTHS - 1));
        if ($start < $earliest) {
            $start = $earliest;
        }

        return ['from' => $start, 'to' => $end];
    }

    /** Mois Y-m décalé de $months mois. */
    public static function shift(string $month, int $months): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        $index = $year * 12 + ($number - 1) + $months;

        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    /** @return list<string> mois Y-m de début à fin inclus */
    public static function months(string $from, string $to): array
    {
        $months = [];
        for ($current = $from; $current <= $to && count($months) < 600; $current = self::shift($current, 1)) {
            $months[] = $current;
        }

        return $months;
    }

    public static function lastDay(string $month): string
    {
        return date('Y-m-t', (int) strtotime($month . '-01'));
    }

    /**
     * Synthèse du rapport.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function summarize(array $rows, string $from, string $to, string $today): array
    {
        $periodStart = $from . '-01';
        $periodEnd = min(self::lastDay($to), $today);
        $open = [
            'total'       => 0,
            'overdue'     => 0,
            'no_due'      => 0,
            'by_kind'     => [],
            'by_severity' => [],
            'by_origin'   => [],
            'by_entity'   => [],
            'by_owner'    => [],
            'by_age'      => array_fill_keys(array_keys(self::AGE_BUCKETS), 0),
        ];
        $closedDays = [];
        $verified = 0;
        $toVerify = 0;
        $openedInPeriod = 0;
        foreach ($rows as $row) {
            if ($row['created'] >= $periodStart && $row['created'] <= $periodEnd) {
                $openedInPeriod++;
            }
            if ($row['closed'] === null) {
                $overdue = self::overdueDays($row, $today) > 0 ? 1 : 0;
                $open['total']++;
                $open['overdue'] += $overdue;
                $open['no_due'] += $row['due'] === null ? 1 : 0;
                $axes = [
                    'by_kind'     => (string) $row['kind'],
                    'by_severity' => (string) $row['severity'],
                    'by_origin'   => (string) $row['origin'],
                    'by_entity'   => (string) $row['entities_id'],
                    'by_owner'    => (string) $row['users_id'],
                ];
                foreach ($axes as $axis => $key) {
                    $open[$axis][$key] ??= ['open' => 0, 'overdue' => 0];
                    $open[$axis][$key]['open']++;
                    $open[$axis][$key]['overdue'] += $overdue;
                }
                $open['by_age'][self::ageBucket(self::days((string) $row['created'], $today))]++;
                continue;
            }
            if ($row['closed'] >= $periodStart && $row['closed'] <= $periodEnd) {
                $closedDays[] = self::days((string) $row['created'], (string) $row['closed']);
                if ($row['kind'] !== self::KIND_REVIEW_ACTION) {
                    $row['verified'] ? $verified++ : $toVerify++;
                }
            }
        }
        sort($closedDays);
        $count = count($closedDays);
        foreach (['by_kind', 'by_severity', 'by_origin', 'by_entity', 'by_owner'] as $axis) {
            uasort($open[$axis], static fn (array $a, array $b): int => $b['open'] <=> $a['open']);
        }

        return [
            'open'             => $open,
            'opened_in_period' => $openedInPeriod,
            'closed_in_period' => $count,
            'mean_closure'     => $count > 0 ? (int) round(array_sum($closedDays) / $count) : null,
            'median_closure'   => $count > 0 ? $closedDays[intdiv($count, 2)] : null,
            'verified'         => $verified,
            'to_verify'        => $toVerify,
            'verified_rate'    => $verified + $toVerify > 0
                ? (int) round(100 * $verified / ($verified + $toVerify)) : null,
            'trend'            => self::trend($rows, $from, $to, $today),
        ];
    }

    /**
     * Tendance mensuelle : écarts ouverts et clôturés dans le mois, stock à la fin du mois.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{month: string, opened: int, closed: int, stock: int}>
     */
    public static function trend(array $rows, string $from, string $to, string $today): array
    {
        $trend = [];
        foreach (self::months($from, $to) as $month) {
            $end = min(self::lastDay($month), $today);
            $point = ['month' => $month, 'opened' => 0, 'closed' => 0, 'stock' => 0];
            foreach ($rows as $row) {
                $point['opened'] += substr((string) $row['created'], 0, 7) === $month ? 1 : 0;
                $point['closed'] += $row['closed'] !== null && substr((string) $row['closed'], 0, 7) === $month
                    ? 1 : 0;
                $point['stock'] += self::isOpen($row, $end) ? 1 : 0;
            }
            $trend[] = $point;
        }

        return $trend;
    }

    /**
     * Date de clôture à enregistrer lors d'une modification : posée en entrant dans un statut
     * clos (ou si elle manque), effacée à la réouverture, inchangée sinon.
     *
     * @param list<string> $closedStatuses
     * @return array{set: bool, value: ?string}
     */
    public static function closureStamp(
        string $before,
        string $after,
        ?string $currentDate,
        array $closedStatuses,
        string $now
    ): array {
        $wasClosed = in_array($before, $closedStatuses, true);
        $isClosed = in_array($after, $closedStatuses, true);
        if ($isClosed && (!$wasClosed || $currentDate === null || $currentDate === '')) {
            return ['set' => true, 'value' => $now];
        }
        if (!$isClosed && $wasClosed) {
            return ['set' => true, 'value' => null];
        }

        return ['set' => false, 'value' => null];
    }

    /** Cellule CSV neutralisée (formules d'un tableur). */
    public static function csvCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}

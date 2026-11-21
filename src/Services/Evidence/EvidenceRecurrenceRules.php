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

namespace GlpiPlugin\Grcmanager\Services\Evidence;

/**
 * Issue #886 : règles pures (sans GLPI) de fraîcheur d'une preuve produite sur un rythme récurrent
 * (revue des journaux chaque semaine, rapport de sauvegarde le dernier jour ouvré du mois...).
 *
 * Deux familles de rythmes :
 * - glissants (jours, semaines, mois, années) : la prochaine preuve est attendue N unités après la
 *   dernière collecte ;
 * - calés sur le calendrier (dernier jour ouvré du mois, du trimestre) : la preuve de la période
 *   suivant celle de la dernière collecte est attendue au dernier jour ouvré de cette période.
 *   Sans collecte, la preuve de la période en cours est attendue.
 *
 * Jours ouvrés : du lundi au vendredi, hors fermetures déclarées dans GLPI (Configuration >
 * Intitulés > Fermetures), passées en paramètre. Rien ici ne modifie une preuve.
 */
final class EvidenceRecurrenceRules
{
    public const UNIT_NONE = '';
    public const UNIT_DAY = 'day';
    public const UNIT_WEEK = 'week';
    public const UNIT_MONTH = 'month';
    public const UNIT_YEAR = 'year';
    public const UNIT_MONTH_END = 'month_end';
    public const UNIT_QUARTER_END = 'quarter_end';

    public const UNITS = [
        self::UNIT_DAY,
        self::UNIT_WEEK,
        self::UNIT_MONTH,
        self::UNIT_YEAR,
        self::UNIT_MONTH_END,
        self::UNIT_QUARTER_END,
    ];

    public const STATUS_NONE = 'none';
    public const STATUS_FRESH = 'fresh';
    public const STATUS_DUE_SOON = 'due_soon';
    public const STATUS_STALE = 'stale';

    /** Garde-fou contre une saisie absurde (toutes les 10 000 semaines). */
    public const MAX_COUNT = 120;

    /** Délai d'alerte maximal avant l'échéance, en jours. */
    public const MAX_WARNING_DAYS = 7;

    public static function isUnit(string $unit): bool
    {
        return in_array($unit, self::UNITS, true);
    }

    public static function normalizeCount(int $count): int
    {
        return max(1, min(self::MAX_COUNT, $count));
    }

    /**
     * Prochaine échéance (incluse) ; null sans rythme.
     *
     * @param string|null $lastCollected date (Y-m-d) de la dernière collecte, null si jamais
     * @param list<array{begin: string, end: string, perpetual: bool}> $holidays fermetures
     */
    public static function nextDue(
        ?string $lastCollected,
        string $unit,
        int $count,
        string $today,
        array $holidays = []
    ): ?string {
        if (!self::isUnit($unit)) {
            return null;
        }
        $count = self::normalizeCount($count);
        $reference = self::isDate($lastCollected) ? (string) $lastCollected : null;

        if ($unit === self::UNIT_MONTH_END || $unit === self::UNIT_QUARTER_END) {
            $months = $unit === self::UNIT_QUARTER_END ? 3 : 1;
            $base = $reference ?? $today;
            [$year, $month] = array_map('intval', explode('-', substr($base, 0, 7)));
            // Début de la période (le trimestre civil commence en janvier, avril, juillet, octobre).
            $month = $months === 3 ? intdiv($month - 1, 3) * 3 + 1 : $month;
            $periods = $reference === null ? 0 : $count;
            $index = $year * 12 + ($month - 1) + $periods * $months + ($months - 1);

            return self::lastBusinessDay(intdiv($index, 12), $index % 12 + 1, $holidays);
        }
        if ($reference === null) {
            // Jamais collectée : attendue dès aujourd'hui.
            return $today;
        }

        return match ($unit) {
            self::UNIT_DAY  => self::addDays($reference, $count),
            self::UNIT_WEEK => self::addDays($reference, 7 * $count),
            self::UNIT_YEAR => self::addMonths($reference, 12 * $count),
            default         => self::addMonths($reference, $count),
        };
    }

    /**
     * Ajoute des mois en restant dans le mois visé : le 31 janvier plus un mois donne le 28 (ou 29)
     * février, jamais le 3 mars.
     */
    public static function addMonths(string $date, int $months): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $index = $year * 12 + ($month - 1) + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;
        $day = min($day, self::daysInMonth($year, $month));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }

    /**
     * Dernier jour ouvré du mois : on recule depuis le dernier jour en sautant samedis, dimanches et
     * fermetures. Un mois entièrement fermé (cas d'école) renvoie son dernier jour.
     *
     * @param list<array{begin: string, end: string, perpetual: bool}> $holidays
     */
    public static function lastBusinessDay(int $year, int $month, array $holidays = []): string
    {
        $last = self::daysInMonth($year, $month);
        for ($day = $last; $day >= 1; $day--) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if (self::isBusinessDay($date, $holidays)) {
                return $date;
            }
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $last);
    }

    /**
     * @param list<array{begin: string, end: string, perpetual: bool}> $holidays
     */
    public static function isBusinessDay(string $date, array $holidays = []): bool
    {
        $weekday = (int) (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format('N');
        if ($weekday >= 6) {
            return false;
        }
        foreach ($holidays as $holiday) {
            if (self::inHoliday($date, $holiday)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une fermeture perpétuelle revient chaque année aux mêmes jours (le 25 décembre...).
     *
     * @param array{begin: string, end: string, perpetual: bool} $holiday
     */
    public static function inHoliday(string $date, array $holiday): bool
    {
        $begin = (string) $holiday['begin'];
        $end = (string) $holiday['end'] !== '' ? (string) $holiday['end'] : $begin;
        if (!self::isDate($begin) || !self::isDate($end)) {
            return false;
        }
        if (!$holiday['perpetual']) {
            return $date >= $begin && $date <= $end;
        }
        $day = substr($date, 5);
        $from = substr($begin, 5);
        $to = substr($end, 5);

        // Fermeture à cheval sur deux années (du 24 décembre au 2 janvier).
        return $from <= $to ? ($day >= $from && $day <= $to) : ($day >= $from || $day <= $to);
    }

    /**
     * Délai d'alerte : une semaine au plus, un quart de la période pour les rythmes courts (un jour
     * pour une preuve quotidienne ou hebdomadaire).
     */
    public static function warningDays(string $unit, int $count): int
    {
        $count = self::normalizeCount($count);
        $periodDays = match ($unit) {
            self::UNIT_DAY  => $count,
            self::UNIT_WEEK => 7 * $count,
            self::UNIT_QUARTER_END => 90 * $count,
            self::UNIT_YEAR => 365 * $count,
            default         => 30 * $count,
        };

        return max(1, min(self::MAX_WARNING_DAYS, intdiv($periodDays, 4)));
    }

    /** L'échéance est incluse : une preuve attendue « le 30 » est encore à l'heure le 30. */
    public static function status(?string $nextDue, string $unit, int $count, string $today): string
    {
        if ($nextDue === null || !self::isUnit($unit)) {
            return self::STATUS_NONE;
        }
        if ($nextDue < $today) {
            return self::STATUS_STALE;
        }

        return $today >= self::addDays($nextDue, -self::warningDays($unit, $count))
            ? self::STATUS_DUE_SOON
            : self::STATUS_FRESH;
    }

    /**
     * Un rappel par échéance : on retient l'échéance pour laquelle le responsable a été prévenu ;
     * une nouvelle collecte déplace l'échéance et réarme le rappel sans rien remettre à zéro.
     */
    public static function reminderDue(string $status, ?string $nextDue, ?string $notifiedDue): bool
    {
        return in_array($status, [self::STATUS_DUE_SOON, self::STATUS_STALE], true)
            && $nextDue !== null
            && $notifiedDue !== $nextDue;
    }

    public static function isDate(?string $date): bool
    {
        return $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))
            ->format('t');
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Privacy;

/**
 * Issue #620 : règles d'une violation de données personnelles inscrite au registre (RGPD, article 33.5).
 *
 * - article 33.1 : notification à l'autorité de contrôle dans les 72 heures après en avoir pris
 *   connaissance, sauf si la violation n'est pas susceptible d'engendrer un risque ; au-delà, les
 *   motifs du retard accompagnent la notification ;
 * - article 33.5 : toute violation est documentée, notifiée ou non : la décision de ne pas notifier
 *   est donc toujours motivée ;
 * - article 34 : information des personnes concernées en cas de risque élevé, sauf exceptions
 *   (article 34.3) : la décision de ne pas les informer est motivée elle aussi.
 *
 * Logique pure (ni $DB ni __()), testée directement.
 */
final class DataBreachRules
{
    public const NATURES = ['confidentiality', 'integrity', 'availability'];

    /** Vide = risque pas encore évalué. */
    public const RISK_LEVELS = ['unlikely', 'risk', 'high'];

    public const AUTHORITY_DECISIONS = ['pending', 'notified', 'not_notified'];

    public const SUBJECTS_DECISIONS = ['pending', 'informed', 'not_informed'];

    public const DEFAULT_DECISION = 'pending';

    public const HOURS_TO_NOTIFY = 72;

    /** En deçà de ce nombre d'heures restantes, l'échéance est signalée comme proche. */
    public const SOON_HOURS = 24;

    public const STATE_PENDING = 'pending';
    public const STATE_SOON = 'soon';
    public const STATE_OVERDUE = 'overdue';
    public const STATE_NOTIFIED = 'notified';
    public const STATE_NOTIFIED_LATE = 'notified_late';
    public const STATE_NOT_NOTIFIED = 'not_notified';

    // Saisies refusées.
    public const PROBLEM_NO_AWARENESS = 'date_awareness';
    public const PROBLEM_NO_AUTHORITY_REASON = 'authority_reason';
    public const PROBLEM_NO_NOTIFICATION_DATE = 'date_notified';
    public const PROBLEM_NOTIFIED_BEFORE_AWARENESS = 'notified_before_awareness';
    public const PROBLEM_NO_DELAY_REASON = 'delay_reason';
    public const PROBLEM_NO_SUBJECTS_REASON = 'subjects_reason';

    // Points d'attention, affichés sans bloquer l'enregistrement.
    public const CHECK_NO_NATURE = 'no_nature';
    public const CHECK_NO_COUNTS = 'no_counts';
    public const CHECK_NO_RISK = 'no_risk';
    public const CHECK_NOT_NOTIFIED_DESPITE_RISK = 'not_notified_despite_risk';
    public const CHECK_HIGH_RISK_SUBJECTS_PENDING = 'high_risk_subjects_pending';
    public const CHECK_NO_MEASURES = 'no_measures';

    public static function normalizeRiskLevel(mixed $value): string
    {
        return self::oneOf($value, self::RISK_LEVELS, '');
    }

    public static function normalizeAuthorityDecision(mixed $value): string
    {
        return self::oneOf($value, self::AUTHORITY_DECISIONS, self::DEFAULT_DECISION);
    }

    public static function normalizeSubjectsDecision(mixed $value): string
    {
        return self::oneOf($value, self::SUBJECTS_DECISIONS, self::DEFAULT_DECISION);
    }

    /**
     * Date et heure saisies (formulaire « AAAA-MM-JJ HH:MM », champ HTML « AAAA-MM-JJTHH:MM », ou
     * date seule) au format de la base, ou null.
     */
    public static function normalizeDateTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = str_replace('T', ' ', trim($value));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    public static function normalizeDate(mixed $value): ?string
    {
        $dateTime = self::normalizeDateTime($value);

        return $dateTime === null ? null : substr($dateTime, 0, 10);
    }

    /**
     * Nombre approximatif (personnes, enregistrements) : entier positif, ou null s'il n'est pas connu.
     */
    public static function normalizeCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        // Espaces et séparateurs de milliers tolérés (« 1 200 », « 1.200 »).
        $digits = preg_replace('/[\s.,\x{00A0}\x{202F}]/u', '', $value);

        return is_string($digits) && $digits !== '' && ctype_digit($digits) && strlen($digits) <= 9
            ? (int) $digits : null;
    }

    /**
     * Échéance de notification à l'autorité : 72 heures après la prise de connaissance.
     */
    public static function deadline(string $awareness): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($awareness . ' +' . self::HOURS_TO_NOTIFY . ' hours'));
    }

    public static function isLate(string $awareness, string $notified): bool
    {
        return strtotime($notified) > strtotime(self::deadline($awareness));
    }

    /**
     * Heures entières restantes avant l'échéance (négatives une fois dépassée).
     */
    public static function hoursLeft(string $awareness, string $now): int
    {
        $seconds = (int) strtotime(self::deadline($awareness)) - (int) strtotime($now);

        return (int) ($seconds >= 0 ? floor($seconds / 3600) : -ceil(-$seconds / 3600));
    }

    /**
     * Saisies qui empêchent l'enregistrement.
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function problems(array $fields): array
    {
        $problems = [];
        $awareness = self::normalizeDateTime($fields['date_awareness'] ?? null);
        if ($awareness === null) {
            $problems[] = self::PROBLEM_NO_AWARENESS;
        }
        $decision = self::normalizeAuthorityDecision($fields['authority_decision'] ?? null);
        if ($decision === 'not_notified' && self::isBlank($fields['authority_reason'] ?? null)) {
            $problems[] = self::PROBLEM_NO_AUTHORITY_REASON;
        }
        if ($decision === 'notified') {
            $notified = self::normalizeDateTime($fields['date_notified'] ?? null);
            if ($notified === null) {
                $problems[] = self::PROBLEM_NO_NOTIFICATION_DATE;
            } elseif ($awareness !== null && strtotime($notified) < strtotime($awareness)) {
                $problems[] = self::PROBLEM_NOTIFIED_BEFORE_AWARENESS;
            } elseif (
                $awareness !== null && self::isLate($awareness, $notified)
                && self::isBlank($fields['delay_reason'] ?? null)
            ) {
                $problems[] = self::PROBLEM_NO_DELAY_REASON;
            }
        }
        if (
            self::normalizeSubjectsDecision($fields['subjects_decision'] ?? null) === 'not_informed'
            && self::isBlank($fields['subjects_reason'] ?? null)
        ) {
            $problems[] = self::PROBLEM_NO_SUBJECTS_REASON;
        }

        return $problems;
    }

    /**
     * Situation de la notification à l'autorité à un instant donné.
     *
     * @param array<string, mixed> $fields
     */
    public static function state(array $fields, string $now): string
    {
        $decision = self::normalizeAuthorityDecision($fields['authority_decision'] ?? null);
        if ($decision === 'not_notified') {
            return self::STATE_NOT_NOTIFIED;
        }
        $awareness = self::normalizeDateTime($fields['date_awareness'] ?? null);
        if ($decision === 'notified') {
            $notified = self::normalizeDateTime($fields['date_notified'] ?? null);

            return $awareness !== null && $notified !== null && self::isLate($awareness, $notified)
                ? self::STATE_NOTIFIED_LATE : self::STATE_NOTIFIED;
        }
        if ($awareness === null) {
            return self::STATE_PENDING;
        }
        $left = (int) strtotime(self::deadline($awareness)) - (int) strtotime($now);
        if ($left < 0) {
            return self::STATE_OVERDUE;
        }

        return $left <= self::SOON_HOURS * 3600 ? self::STATE_SOON : self::STATE_PENDING;
    }

    /**
     * Points d'attention (le registre reste enregistrable).
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function checks(array $fields): array
    {
        $checks = [];
        if (ProcessingActivityRules::decodeList($fields['nature'] ?? '', self::NATURES) === []) {
            $checks[] = self::CHECK_NO_NATURE;
        }
        if (
            self::normalizeCount($fields['subjects_count'] ?? null) === null
            && self::normalizeCount($fields['records_count'] ?? null) === null
        ) {
            $checks[] = self::CHECK_NO_COUNTS;
        }
        $risk = self::normalizeRiskLevel($fields['risk_level'] ?? null);
        if ($risk === '') {
            $checks[] = self::CHECK_NO_RISK;
        }
        $decision = self::normalizeAuthorityDecision($fields['authority_decision'] ?? null);
        if ($decision === 'not_notified' && in_array($risk, ['risk', 'high'], true)) {
            $checks[] = self::CHECK_NOT_NOTIFIED_DESPITE_RISK;
        }
        if ($risk === 'high' && self::normalizeSubjectsDecision($fields['subjects_decision'] ?? null) === 'pending') {
            $checks[] = self::CHECK_HIGH_RISK_SUBJECTS_PENDING;
        }
        if (self::isBlank($fields['measures_taken'] ?? null) && self::isBlank($fields['measures_planned'] ?? null)) {
            $checks[] = self::CHECK_NO_MEASURES;
        }

        return $checks;
    }

    public static function isBlank(mixed $value): bool
    {
        return !is_scalar($value) || trim((string) $value) === '';
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }
}

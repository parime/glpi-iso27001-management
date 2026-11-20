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

namespace GlpiPlugin\Grcmanager\Services\Context;

/**
 * Issue #771 : règles du registre des contacts officiels (ISO 27001, mesures A.5.5 et A.5.6), sans
 * GLPI pour être testées seules.
 *
 * Un contact officiel est une partie intéressée du contexte de l'organisation (ContextItem) marquée
 * « contact officiel » : autorité de contrôle, CSIRT, forces de l'ordre, groupe d'intérêt... Ses
 * coordonnées doivent être vérifiées au moins une fois par an.
 */
final class OfficialContactRules
{
    /** Délai au-delà duquel un contact non vérifié est signalé. */
    public const VERIFICATION_MONTHS = 12;

    /** Intervalle minimal entre deux relances d'un même contact. */
    public const REMINDER_INTERVAL_DAYS = 30;

    public const STATUS_OK = 'ok';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_NEVER = 'never';

    public const CONTEXT_INCIDENT = 'incident';
    public const CONTEXT_BREACH = 'breach';

    /** Catégories des contacts relevant de la mesure A.5.5 (relations avec les autorités). */
    public const AUTHORITY_CATEGORIES = [
        'supervisory_authority', 'csirt', 'law_enforcement', 'local_authority', 'sector_regulator',
    ];

    /** Catégories relevant de la mesure A.5.6 (relations avec des groupes d'intérêt). */
    public const INTEREST_GROUP_CATEGORIES = ['interest_group'];

    /** @var list<string> */
    public const CATEGORIES = [
        'supervisory_authority', 'csirt', 'law_enforcement', 'local_authority', 'sector_regulator',
        'interest_group', 'insurer', 'hosting_provider', 'other',
    ];

    /** Mesure de l'Annexe A => catégories de contacts qui en constituent la preuve. */
    public const CONTROL_CATEGORIES = [
        'A.5.5' => self::AUTHORITY_CATEGORIES,
        'A.5.6' => self::INTEREST_GROUP_CATEGORIES,
    ];

    // ------------------------------------------------------------------
    // Lot 2 : prises de contact tracées depuis un incident ou une violation de données
    // ------------------------------------------------------------------

    /** Délai maximal saisi en heures (un an). */
    public const MAX_DELAY_HOURS = 8760;

    /** Le contact a été prévenu (date, référence du dossier). */
    public const DECISION_NOTIFIED = 'notified';

    /** Décision motivée de ne pas le prévenir pour cet événement. */
    public const DECISION_NOT_REQUIRED = 'not_required';

    /** @var list<string> */
    public const DECISIONS = [self::DECISION_NOTIFIED, self::DECISION_NOT_REQUIRED];

    public const NOTICE_ON_TIME = 'on_time';
    public const NOTICE_LATE = 'late';
    public const NOTICE_NOT_REQUIRED = 'not_required';
    public const NOTICE_PENDING = 'pending';
    public const NOTICE_OVERDUE = 'overdue';

    public const REFUSED_DECISION = 'decision';
    public const REFUSED_DATE = 'date';
    public const REFUSED_FUTURE = 'future';
    public const REFUSED_BEFORE_START = 'before_start';
    public const REFUSED_REASON = 'reason';

    public static function normalizeCategory(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::CATEGORIES, true) ? $value : 'other';
    }

    /**
     * @return list<string>|null catégories prouvant la mesure, null si la mesure n'est pas concernée
     */
    public static function categoriesForControl(string $code): ?array
    {
        return self::CONTROL_CATEGORIES[$code] ?? null;
    }

    /**
     * État de la vérification au jour $today : jamais vérifié, vérifié il y a plus de 12 mois, ou à jour.
     */
    public static function verificationStatus(?string $verifiedDate, string $today): string
    {
        if (!self::isDate($verifiedDate)) {
            return self::STATUS_NEVER;
        }

        return substr((string) $verifiedDate, 0, 10) < self::limitDate($today)
            ? self::STATUS_OVERDUE
            : self::STATUS_OK;
    }

    /**
     * Date à laquelle la prochaine vérification est due (12 mois après la dernière), null sans vérification.
     */
    public static function nextVerificationDate(?string $verifiedDate): ?string
    {
        if (!self::isDate($verifiedDate)) {
            return null;
        }

        return (new \DateTimeImmutable(substr((string) $verifiedDate, 0, 10)))
            ->modify('+' . self::VERIFICATION_MONTHS . ' months')
            ->format('Y-m-d');
    }

    /**
     * Relancer le propriétaire : contact à vérifier, et pas de relance depuis 30 jours.
     */
    public static function shouldRemind(?string $verifiedDate, ?string $lastReminder, string $today): bool
    {
        if (self::verificationStatus($verifiedDate, $today) === self::STATUS_OK) {
            return false;
        }
        if (!self::isDate($lastReminder)) {
            return true;
        }
        $limit = (new \DateTimeImmutable(substr($today, 0, 10)))
            ->modify('-' . self::REMINDER_INTERVAL_DAYS . ' days')
            ->format('Y-m-d');

        return substr((string) $lastReminder, 0, 10) <= $limit;
    }

    /**
     * Le contact est-il à prévenir pour ce type d'événement (incident de sécurité ou violation de données) ?
     *
     * @param array<string, mixed> $row
     */
    public static function concerns(array $row, string $context): bool
    {
        if (empty($row['is_official_contact'])) {
            return false;
        }

        return match ($context) {
            self::CONTEXT_INCIDENT => !empty($row['on_security_incident']),
            self::CONTEXT_BREACH   => !empty($row['on_data_breach']),
            default                => false,
        };
    }

    /**
     * Synthèse de vérification d'une liste de contacts.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return array{total: int, verified: int, to_verify: int}
     */
    public static function summary(iterable $rows, string $today): array
    {
        $summary = ['total' => 0, 'verified' => 0, 'to_verify' => 0];
        foreach ($rows as $row) {
            $summary['total']++;
            $status = self::verificationStatus(
                isset($row['contact_verified_date']) ? (string) $row['contact_verified_date'] : null,
                $today
            );
            $summary[$status === self::STATUS_OK ? 'verified' : 'to_verify']++;
        }

        return $summary;
    }

    /**
     * Délai de prévenance en heures (0 : aucun délai chiffré), borné à un an.
     */
    public static function normalizeDelayHours(mixed $value): int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return 0;
        }

        return max(0, min(self::MAX_DELAY_HOURS, (int) $value));
    }

    public static function normalizeDecision(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::DECISIONS, true) ? $value : '';
    }

    /**
     * Date et heure saisies (« 2026-03-10 09:30 », « 2026-03-10T09:30 », secondes facultatives),
     * au format de la base, ou null si elles sont mal formées.
     */
    public static function normalizeDateTime(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim(str_replace('T', ' ', (string) $value)) : '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', sprintf('%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00'));
        if ($date === false || $date->format('Y-m-d') !== $m[1]) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Échéance de prévenance : début de l'événement (détection de l'incident, prise de connaissance
     * de la violation) plus le délai du contact. Null sans délai chiffré ou sans date de début.
     */
    public static function deadline(?string $start, int $hours): ?string
    {
        $start = self::normalizeDateTime(substr((string) $start, 0, 19));
        if ($start === null || $hours <= 0) {
            return null;
        }

        return (new \DateTimeImmutable($start))->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
    }

    /**
     * Motif du refus d'une prise de contact, ou null si elle peut être tracée. $date est déjà
     * normalisée (null : mal formée).
     */
    public static function traceRefusal(string $decision, ?string $date, string $reason, ?string $start, string $now): ?string
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            return self::REFUSED_DECISION;
        }
        if ($decision === self::DECISION_NOT_REQUIRED && trim($reason) === '') {
            return self::REFUSED_REASON;
        }
        if ($date === null) {
            return self::REFUSED_DATE;
        }
        if ($date > $now) {
            return self::REFUSED_FUTURE;
        }
        $start = self::normalizeDateTime(substr((string) $start, 0, 19));
        if ($decision === self::DECISION_NOTIFIED && $start !== null && $date < $start) {
            return self::REFUSED_BEFORE_START;
        }

        return null;
    }

    /**
     * Situation d'un contact pour un événement, d'après ses prises de contact tracées :
     * - prévenu dans le délai ou en retard (première prévenance tracée) ;
     * - non nécessaire (décision motivée, sans prévenance) ;
     * - à prévenir, ou échéance dépassée sans rien de tracé.
     *
     * @param list<array<string, mixed>> $traces lignes (decision, date_notified)
     * @return array{state: string, deadline: ?string, date: ?string}
     */
    public static function noticeState(array $traces, ?string $start, int $hours, string $now): array
    {
        $deadline = self::deadline($start, $hours);
        $first = null;
        $notRequired = false;
        foreach ($traces as $trace) {
            $decision = (string) ($trace['decision'] ?? '');
            $date = self::normalizeDateTime(substr((string) ($trace['date_notified'] ?? ''), 0, 19));
            if ($decision === self::DECISION_NOT_REQUIRED) {
                $notRequired = true;
            } elseif ($decision === self::DECISION_NOTIFIED && $date !== null && ($first === null || $date < $first)) {
                $first = $date;
            }
        }
        if ($first !== null) {
            $late = $deadline !== null && $first > $deadline;

            return ['state' => $late ? self::NOTICE_LATE : self::NOTICE_ON_TIME, 'deadline' => $deadline, 'date' => $first];
        }
        if ($notRequired) {
            return ['state' => self::NOTICE_NOT_REQUIRED, 'deadline' => $deadline, 'date' => null];
        }
        $overdue = $deadline !== null && $now > $deadline;

        return ['state' => $overdue ? self::NOTICE_OVERDUE : self::NOTICE_PENDING, 'deadline' => $deadline, 'date' => null];
    }

    private static function limitDate(string $today): string
    {
        return (new \DateTimeImmutable(substr($today, 0, 10)))
            ->modify('-' . self::VERIFICATION_MONTHS . ' months')
            ->format('Y-m-d');
    }

    private static function isDate(?string $value): bool
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 && !str_starts_with($value, '0000');
    }
}

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

use DateTimeImmutable;

/**
 * Issue #375 : règles d'une demande d'exercice des droits (RGPD, articles 15 à 22). L'article 12.3 donne
 * un mois pour répondre, prolongeable de deux mois avec justification. Sans base : testable seul.
 */
final class DataSubjectRequestRules
{
    public const TYPES = ['access', 'rectification', 'erasure', 'restriction', 'portability', 'objection'];

    public const STATUSES = ['received', 'identity_check', 'in_progress', 'answered', 'refused'];

    public const CLOSED_STATUSES = ['answered', 'refused'];

    public const DEFAULT_TYPE = 'access';

    public const DEFAULT_STATUS = 'received';

    public const MONTHS_TO_ANSWER = 1;

    public const MONTHS_WHEN_EXTENDED = 3;

    /** À partir de ce nombre de jours restants, l'échéance est signalée comme proche. */
    public const SOON_DAYS = 7;

    public const STATE_CLOSED = 'closed';
    public const STATE_LATE_ANSWER = 'late_answer';
    public const STATE_OVERDUE = 'overdue';
    public const STATE_SOON = 'soon';
    public const STATE_OK = 'ok';

    public static function normalizeType(mixed $value): string
    {
        return in_array($value, self::TYPES, true) ? (string) $value : self::DEFAULT_TYPE;
    }

    public static function normalizeStatus(mixed $value): string
    {
        return in_array($value, self::STATUSES, true) ? (string) $value : self::DEFAULT_STATUS;
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED_STATUSES, true);
    }

    /**
     * Une date valide au format Y-m-d, ou null.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $day = substr(trim($value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date !== false && $date->format('Y-m-d') === $day ? $day : null;
    }

    /**
     * Échéance : même jour du mois, ou dernier jour du mois quand ce jour n'existe pas (31 janvier + 1 mois
     * = 28 février), selon le calcul des délais du règlement (CEE) 1182/71.
     */
    public static function dueDate(string $received, bool $extended): ?string
    {
        $day = self::normalizeDate($received);
        if ($day === null) {
            return null;
        }
        $months = $extended ? self::MONTHS_WHEN_EXTENDED : self::MONTHS_TO_ANSWER;
        $date = new DateTimeImmutable($day);
        $target = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $dayOfMonth = min((int) $date->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $dayOfMonth)->format('Y-m-d');
    }

    /**
     * Jours restants, négatifs une fois l'échéance dépassée.
     */
    public static function daysLeft(string $due, string $today): int
    {
        $difference = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due));

        return (int) $difference->format('%r%a');
    }

    public static function state(string $status, ?string $due, ?string $answered, string $today): string
    {
        if ($due === null) {
            return self::STATE_OK;
        }
        if (self::isClosed($status)) {
            return $answered !== null && $answered > $due ? self::STATE_LATE_ANSWER : self::STATE_CLOSED;
        }
        $left = self::daysLeft($due, $today);
        if ($left < 0) {
            return self::STATE_OVERDUE;
        }

        return $left <= self::SOON_DAYS ? self::STATE_SOON : self::STATE_OK;
    }

    /**
     * Ce qui empêche d'enregistrer la demande.
     *
     * @param array<string, mixed> $fields
     * @return list<string> extension_reason, refusal_reason, date_answered
     */
    public static function problems(array $fields): array
    {
        $problems = [];
        if (!empty($fields['is_extended']) && trim((string) ($fields['extension_reason'] ?? '')) === '') {
            $problems[] = 'extension_reason';
        }
        $status = (string) ($fields['status'] ?? '');
        if ($status === 'refused' && trim((string) ($fields['refusal_reason'] ?? '')) === '') {
            $problems[] = 'refusal_reason';
        }
        if (self::isClosed($status) && self::normalizeDate($fields['date_answered'] ?? null) === null) {
            $problems[] = 'date_answered';
        }

        return $problems;
    }
}

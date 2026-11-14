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

namespace GlpiPlugin\Grcmanager\Services\Audit;

/**
 * Issue #538 : règles des demandes de preuves d'un audit et de l'accès de l'auditeur (PHP pur,
 * testé seul).
 *
 * Cycle d'une demande : « à fournir » (requested) puis « soumise » (submitted) quand la personne
 * désignée envoie ses pièces ; l'audit l'accepte (accepted) ou la renvoie « à compléter »
 * (to_complete) avec un motif, et la personne la soumet de nouveau. Seules ces actions humaines
 * changent un statut : une échéance dépassée est signalée et relancée, jamais close ni réattribuée.
 *
 * L'auditeur (accès en lecture limité à un audit et dans le temps) ne voit que les demandes déjà
 * soumises une fois, et seulement les pièces soumises : une pièce en cours de préparation ne lui est
 * jamais montrée.
 */
final class EvidenceRequestRules
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_TO_COMPLETE = 'to_complete';
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SUBMITTED, self::STATUS_TO_COMPLETE, self::STATUS_ACCEPTED,
    ];

    public const DECISION_ACCEPT = 'accept';
    public const DECISION_TO_COMPLETE = 'to_complete';

    /** Première relance : ce nombre de jours avant l'échéance. */
    public const REMINDER_DAYS_BEFORE = 7;
    /** Après l'échéance : au plus une relance par période. */
    public const REMINDER_INTERVAL_DAYS = 7;

    /** Durée par défaut et durée maximale d'un accès auditeur, en jours. */
    public const DEFAULT_ACCESS_DAYS = 30;
    public const MAX_ACCESS_DAYS = 366;

    /** Longueur maximale de la référence d'une demande (colonne varchar(255)). */
    public const MAX_REFERENCE_LENGTH = 255;

    public static function isStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /** La demande attend la personne désignée (à fournir ou à compléter). */
    public static function isWaitingForAssignee(string $status): bool
    {
        return in_array($status, [self::STATUS_REQUESTED, self::STATUS_TO_COMPLETE], true);
    }

    public static function canAddPiece(string $status): bool
    {
        return self::isWaitingForAssignee($status);
    }

    /**
     * Soumission : la demande attend la personne et au moins une pièce est jointe (une pièce déjà
     * soumise suffit pour répondre à « à compléter » par un commentaire).
     */
    public static function canSubmit(string $status, int $pieces): bool
    {
        return self::isWaitingForAssignee($status) && $pieces > 0;
    }

    /** Seule une demande soumise attend la revue de l'audit. */
    public static function canReview(string $status): bool
    {
        return $status === self::STATUS_SUBMITTED;
    }

    /**
     * Statut après la revue, ou null si la décision est refusée (inconnue, demande non soumise,
     * renvoi sans motif).
     */
    public static function statusAfterReview(string $status, string $decision, string $reason): ?string
    {
        if (!self::canReview($status)) {
            return null;
        }
        if ($decision === self::DECISION_ACCEPT) {
            return self::STATUS_ACCEPTED;
        }
        if ($decision === self::DECISION_TO_COMPLETE && trim($reason) !== '') {
            return self::STATUS_TO_COMPLETE;
        }

        return null;
    }

    /** En retard : la demande attend toujours la personne et l'échéance est passée. */
    public static function isOverdue(string $status, ?string $dueDate, string $today): bool
    {
        $due = self::date($dueDate);

        return self::isWaitingForAssignee($status) && $due !== null && $today > $due;
    }

    public static function daysLate(string $status, ?string $dueDate, string $today): int
    {
        if (!self::isOverdue($status, $dueDate, $today)) {
            return 0;
        }

        return (int) ((strtotime($today) - strtotime((string) self::date($dueDate))) / 86400);
    }

    /**
     * Relance de la personne désignée : une fois dans les REMINDER_DAYS_BEFORE jours qui précèdent
     * l'échéance, puis au plus une fois tous les REMINDER_INTERVAL_DAYS jours après l'échéance.
     * Jamais pour une demande soumise ou acceptée, ni sans échéance.
     */
    public static function isReminderDue(string $status, ?string $dueDate, ?string $lastReminder, string $today): bool
    {
        $due = self::date($dueDate);
        if (!self::isWaitingForAssignee($status) || $due === null) {
            return false;
        }
        $windowStart = date('Y-m-d', (int) strtotime($due . ' -' . self::REMINDER_DAYS_BEFORE . ' days'));
        if ($today < $windowStart) {
            return false;
        }
        $last = self::date($lastReminder);
        if ($last === null) {
            return true;
        }
        if ($today <= $due) {
            // Avant l'échéance : une seule relance dans la fenêtre.
            return $last < $windowStart;
        }
        // Après l'échéance : une relance au plus tous les REMINDER_INTERVAL_DAYS jours, la première
        // le lendemain de l'échéance même si une relance a eu lieu juste avant.
        if ($last <= $due) {
            return true;
        }

        return $last <= date('Y-m-d', (int) strtotime($today . ' -' . self::REMINDER_INTERVAL_DAYS . ' days'));
    }

    /**
     * Avancement d'un audit : nombre de demandes par statut, pourcentage acceptées et couleur.
     *
     * @param list<string> $statuses
     * @return array{total: int, requested: int, submitted: int, to_complete: int, accepted: int,
     *     percent: int, provided_percent: int, color: string}
     */
    public static function progress(array $statuses): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($statuses as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = array_sum($counts);
        $percent = $total > 0 ? (int) floor($counts[self::STATUS_ACCEPTED] * 100 / $total) : 0;
        $provided = $total > 0
            ? (int) floor(($counts[self::STATUS_ACCEPTED] + $counts[self::STATUS_SUBMITTED]) * 100 / $total)
            : 0;
        $color = match (true) {
            $total === 0      => 'secondary',
            $percent >= 100   => 'green',
            $percent >= 50    => 'blue',
            default           => 'orange',
        };

        return [
            'total'            => $total,
            'requested'        => $counts[self::STATUS_REQUESTED],
            'submitted'        => $counts[self::STATUS_SUBMITTED],
            'to_complete'      => $counts[self::STATUS_TO_COMPLETE],
            'accepted'         => $counts[self::STATUS_ACCEPTED],
            'percent'          => $percent,
            'provided_percent' => $provided,
            'color'            => $color,
        ];
    }

    /** L'auditeur voit une demande dès qu'elle a été soumise une fois. */
    public static function isVisibleToAuditor(string $status, ?string $dateSubmitted): bool
    {
        return $status !== self::STATUS_REQUESTED || self::date($dateSubmitted) !== null;
    }

    /** L'auditeur ne voit que les pièces soumises. */
    public static function isPieceVisibleToAuditor(bool $isSubmitted): bool
    {
        return $isSubmitted;
    }

    /** Accès auditeur en vigueur : commencé, pas terminé, pas révoqué. */
    public static function isAccessActive(?string $begin, ?string $end, ?string $revoked, string $today): bool
    {
        $begin = self::date($begin);
        $end = self::date($end);

        return $begin !== null && $end !== null && self::date($revoked) === null
            && $today >= $begin && $today <= $end;
    }

    /**
     * Date de fin d'un accès auditeur saisie : une date valide, pas avant le début, au plus
     * MAX_ACCESS_DAYS jours après. Null si elle ne convient pas.
     */
    public static function accessEnd(mixed $input, string $begin): ?string
    {
        $end = self::parseDate($input);
        if ($end === null || $end < $begin) {
            return null;
        }
        $max = date('Y-m-d', (int) strtotime($begin . ' +' . self::MAX_ACCESS_DAYS . ' days'));

        return $end <= $max ? $end : null;
    }

    public static function defaultAccessEnd(string $begin): string
    {
        return date('Y-m-d', (int) strtotime($begin . ' +' . self::DEFAULT_ACCESS_DAYS . ' days'));
    }

    /**
     * Date saisie (AAAA-MM-JJ, ou JJ/MM/AAAA d'un tableur), null si vide ou invalide.
     */
    public static function parseDate(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    public static function reference(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));

        return mb_substr($text, 0, self::MAX_REFERENCE_LENGTH);
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        return substr($value, 0, 10);
    }
}

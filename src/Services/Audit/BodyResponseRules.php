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
 * Issue #772, lot 3 : réponse aux constats de l'organisme de certification. Règles pures (sans
 * GLPI).
 *
 * Après l'audit, l'organisme fixe deux délais dans son rapport : l'envoi du plan d'actions
 * (correction, cause, action corrective pour chaque non-conformité), puis l'envoi des preuves de
 * levée. L'organisme accepte le plan ou demande de le compléter. Ces états sont calculés à partir
 * des dates saisies : rien n'est modifié automatiquement (ni statut, ni responsable, ni échéance).
 *
 * Une réponse est attendue pour une non-conformité (majeure ou mineure) ; elle reste facultative
 * pour un point sensible ou une piste de progrès, sauf si elle a été envoyée.
 */
final class BodyResponseRules
{
    /** Nombre de jours avant le délai à partir duquel l'échéance est signalée comme proche. */
    public const SOON_DAYS = 7;

    public const DECISION_PENDING = '';
    public const DECISION_ACCEPTED = 'accepted';
    public const DECISION_TO_COMPLETE = 'to_complete';

    public const DECISIONS = [self::DECISION_PENDING, self::DECISION_ACCEPTED, self::DECISION_TO_COMPLETE];

    public const RESPONSE_NOT_REQUIRED = 'not_required';
    public const RESPONSE_TO_SEND = 'to_send';
    public const RESPONSE_DUE_SOON = 'due_soon';
    public const RESPONSE_OVERDUE = 'overdue';
    public const RESPONSE_SENT = 'sent';
    public const RESPONSE_ACCEPTED = 'accepted';
    public const RESPONSE_TO_COMPLETE = 'to_complete';

    public const RESPONSE_STATES = [
        self::RESPONSE_OVERDUE,
        self::RESPONSE_TO_COMPLETE,
        self::RESPONSE_DUE_SOON,
        self::RESPONSE_TO_SEND,
        self::RESPONSE_SENT,
        self::RESPONSE_ACCEPTED,
        self::RESPONSE_NOT_REQUIRED,
    ];

    public const EVIDENCE_NOT_REQUIRED = 'not_required';
    public const EVIDENCE_TO_SEND = 'to_send';
    public const EVIDENCE_DUE_SOON = 'due_soon';
    public const EVIDENCE_OVERDUE = 'overdue';
    public const EVIDENCE_SENT = 'sent';

    public const EVIDENCE_STATES = [
        self::EVIDENCE_OVERDUE,
        self::EVIDENCE_DUE_SOON,
        self::EVIDENCE_TO_SEND,
        self::EVIDENCE_SENT,
        self::EVIDENCE_NOT_REQUIRED,
    ];

    /** Décision connue, sinon « en attente » : jamais une acceptation par erreur de saisie. */
    public static function normalizeDecision(string $decision): string
    {
        return in_array($decision, self::DECISIONS, true) ? $decision : self::DECISION_PENDING;
    }

    /** Date AAAA-MM-JJ valide, sinon null (champ vide, valeur nulle de MariaDB, saisie invalide). */
    public static function normalizeDate(mixed $value): ?string
    {
        $date = is_string($value) ? trim(substr($value, 0, 10)) : '';
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    /** Une réponse est-elle attendue pour ce constat de l'organisme ? (non-conformité uniquement) */
    public static function isResponseRequired(?string $category): bool
    {
        return in_array(
            $category,
            [CertificationAuditRules::CATEGORY_MAJOR_NC, CertificationAuditRules::CATEGORY_MINOR_NC],
            true
        );
    }

    /**
     * Jours restants jusqu'au délai (négatif une fois dépassé), null sans délai.
     */
    public static function daysLeft(?string $dueDate, string $today): ?int
    {
        $due = self::normalizeDate($dueDate);
        $now = self::normalizeDate($today);
        if ($due === null || $now === null) {
            return null;
        }
        $diff = (new \DateTimeImmutable($now))->diff(new \DateTimeImmutable($due));

        return $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
    }

    /** À envoyer, bientôt dû ou en retard, selon le délai (aucun délai : à envoyer). */
    private static function pendingState(
        ?string $dueDate,
        string $today,
        string $toSend,
        string $soon,
        string $late
    ): string
    {
        $days = self::daysLeft($dueDate, $today);
        if ($days === null) {
            return $toSend;
        }
        if ($days < 0) {
            return $late;
        }

        return $days <= self::SOON_DAYS ? $soon : $toSend;
    }

    /**
     * État de la réponse (plan d'actions) d'un constat.
     *
     * @param ?string $sentDate date d'envoi de la réponse à l'organisme
     * @param ?string $dueDate  délai de réponse fixé par l'organisme (audit)
     */
    public static function responseState(
        ?string $category,
        ?string $sentDate,
        ?string $dueDate,
        string $decision,
        string $today
    ): string {
        $decision = self::normalizeDecision($decision);
        if (self::normalizeDate($sentDate) !== null) {
            return match ($decision) {
                self::DECISION_ACCEPTED    => self::RESPONSE_ACCEPTED,
                self::DECISION_TO_COMPLETE => self::RESPONSE_TO_COMPLETE,
                default                    => self::RESPONSE_SENT,
            };
        }
        if (!self::isResponseRequired($category)) {
            return self::RESPONSE_NOT_REQUIRED;
        }

        return self::pendingState(
            $dueDate,
            $today,
            self::RESPONSE_TO_SEND,
            self::RESPONSE_DUE_SOON,
            self::RESPONSE_OVERDUE
        );
    }

    /**
     * État de l'envoi des preuves de levée d'un constat.
     *
     * @param ?string $sentDate date d'envoi des preuves à l'organisme
     * @param ?string $dueDate  délai d'envoi des preuves fixé par l'organisme (audit)
     */
    public static function evidenceState(?string $category, ?string $sentDate, ?string $dueDate, string $today): string
    {
        if (self::normalizeDate($sentDate) !== null) {
            return self::EVIDENCE_SENT;
        }
        if (!self::isResponseRequired($category)) {
            return self::EVIDENCE_NOT_REQUIRED;
        }

        return self::pendingState(
            $dueDate,
            $today,
            self::EVIDENCE_TO_SEND,
            self::EVIDENCE_DUE_SOON,
            self::EVIDENCE_OVERDUE
        );
    }

    /** La réponse demande-t-elle encore une action de notre part ? */
    public static function responseNeedsAction(string $state): bool
    {
        return in_array(
            $state,
            [self::RESPONSE_TO_SEND, self::RESPONSE_DUE_SOON, self::RESPONSE_OVERDUE, self::RESPONSE_TO_COMPLETE],
            true
        );
    }

    public static function evidenceNeedsAction(string $state): bool
    {
        return in_array($state, [self::EVIDENCE_TO_SEND, self::EVIDENCE_DUE_SOON, self::EVIDENCE_OVERDUE], true);
    }

    /**
     * Comptages par état de réponse et de preuve.
     *
     * @param list<array{response_state: string, evidence_state: string}> $findings
     * @return array{total: int, response: array<string, int>, evidence: array<string, int>,
     *     responses_to_send: int, evidence_to_send: int, late: int}
     */
    public static function summary(array $findings): array
    {
        $response = array_fill_keys(self::RESPONSE_STATES, 0);
        $evidence = array_fill_keys(self::EVIDENCE_STATES, 0);
        $responsesToSend = 0;
        $evidenceToSend = 0;
        foreach ($findings as $finding) {
            $r = in_array($finding['response_state'], self::RESPONSE_STATES, true)
                ? $finding['response_state']
                : self::RESPONSE_NOT_REQUIRED;
            $e = in_array($finding['evidence_state'], self::EVIDENCE_STATES, true)
                ? $finding['evidence_state']
                : self::EVIDENCE_NOT_REQUIRED;
            $response[$r]++;
            $evidence[$e]++;
            $responsesToSend += self::responseNeedsAction($r) ? 1 : 0;
            $evidenceToSend += self::evidenceNeedsAction($e) ? 1 : 0;
        }

        return [
            'total'             => count($findings),
            'response'          => $response,
            'evidence'          => $evidence,
            'responses_to_send' => $responsesToSend,
            'evidence_to_send'  => $evidenceToSend,
            'late'              => $response[self::RESPONSE_OVERDUE] + $evidence[self::EVIDENCE_OVERDUE],
        ];
    }

    /**
     * État d'un délai de l'audit : aucun délai, tenu (plus rien à envoyer), à venir, proche ou
     * dépassé.
     */
    public static function deadlineState(?string $dueDate, int $stillToSend, string $today): string
    {
        $days = self::daysLeft($dueDate, $today);
        if ($days === null) {
            return 'none';
        }
        if ($stillToSend === 0) {
            return 'done';
        }
        if ($days < 0) {
            return 'overdue';
        }

        return $days <= self::SOON_DAYS ? 'soon' : 'upcoming';
    }
}

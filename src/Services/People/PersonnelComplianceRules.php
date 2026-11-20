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

namespace GlpiPlugin\Grcmanager\Services\People;

/**
 * Issue #546 : fiche de conformité sécurité d'un collaborateur, sans dépendance à GLPI (testée
 * unitairement). Chaque composante (vérification préalable, engagement de confidentialité,
 * formations, charte, politiques, revue des accès, compte) reçoit un état :
 *
 * - ok : fait ;
 * - pending : pas encore fait, mais encore dans le délai ;
 * - late : écart, le délai est dépassé ;
 * - not_tracked : la source n'est pas disponible (plugin assetsign absent) ;
 * - not_applicable : rien n'est demandé à la personne (aucune campagne ne la concerne, composante
 *   désactivée par un poids nul).
 *
 * Le délai est la règle « dans les N jours suivant l'arrivée » (date de début du compte, à défaut
 * sa création) ; pour une demande faite plus tard (formation assignée, charte envoyée), N jours
 * après la demande ; pour une campagne de prise de connaissance, jamais avant son échéance. Ces
 * règles signalent : elles ne modifient jamais rien.
 *
 * Les composantes renvoient un code de détail et ses paramètres ; le texte affiché est construit
 * par GlpiPlugin\Grcmanager\PersonnelCompliance (traductions de GLPI).
 */
final class PersonnelComplianceRules
{
    public const OK = 'ok';
    public const PENDING = 'pending';
    public const LATE = 'late';
    public const NOT_TRACKED = 'not_tracked';
    public const NOT_APPLICABLE = 'not_applicable';

    public const SCREENING = 'screening';
    public const NDA = 'nda';
    public const TRAINING = 'training';
    public const CHARTER = 'charter';
    public const POLICIES = 'policies';
    public const ACCESS_REVIEW = 'access_review';
    public const ACCOUNT = 'account';

    /** Composantes, dans l'ordre d'affichage. */
    public const COMPONENTS = [
        self::SCREENING, self::NDA, self::TRAINING, self::CHARTER, self::POLICIES, self::ACCESS_REVIEW,
        self::ACCOUNT,
    ];

    /** Mesures de l'Annexe A auxquelles chaque composante sert de preuve (références seules). */
    public const REFERENCES = [
        self::SCREENING     => 'A.6.1',
        self::NDA           => 'A.6.6',
        self::TRAINING      => 'A.6.3',
        self::CHARTER       => 'A.6.2',
        self::POLICIES      => 'A.5.1, A.6.3',
        self::ACCESS_REVIEW => 'A.5.18',
        self::ACCOUNT       => 'A.5.18, A.6.5',
    ];

    /** Statuts de la vérification préalable à l'embauche. */
    public const SCREENING_DONE = 'done';
    public const SCREENING_NOT_APPLICABLE = 'not_applicable';

    public const DEFAULT_DAYS = 30;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 365;

    /** Poids proposés pour chaque composante (0 : composante non suivie). */
    public const WEIGHTS = [0, 1, 2, 3];
    public const DEFAULT_WEIGHT = 1;

    /** Une revue des accès vaut pour 12 mois ; une échéance à moins de 30 jours est signalée. */
    public const REVIEW_MONTHS = 12;
    public const SOON_DAYS = 30;

    public static function normalizeDays(mixed $days): int
    {
        if (!is_numeric($days)) {
            return self::DEFAULT_DAYS;
        }
        $days = (int) $days;

        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS ? $days : self::DEFAULT_DAYS;
    }

    /**
     * Poids de chaque composante : valeur connue gardée, tout le reste au poids par défaut.
     *
     * @return array<string, int>
     */
    public static function normalizeWeights(mixed $weights): array
    {
        $weights = is_array($weights) ? $weights : [];
        $normalized = [];
        foreach (self::COMPONENTS as $component) {
            $value = $weights[$component] ?? null;
            $normalized[$component] = is_numeric($value) && in_array((int) $value, self::WEIGHTS, true)
                ? (int) $value
                : self::DEFAULT_WEIGHT;
        }

        return $normalized;
    }

    /**
     * Date limite : N jours après la plus tardive des dates de départ (arrivée, demande...).
     */
    public static function deadline(int $days, ?string ...$starts): ?string
    {
        $latest = null;
        foreach ($starts as $start) {
            $date = self::day($start);
            if ($date !== null && ($latest === null || $date > $latest)) {
                $latest = $date;
            }
        }
        if ($latest === null) {
            return null;
        }

        return date('Y-m-d', (int) strtotime($latest . ' +' . $days . ' days'));
    }

    /**
     * Fiche complète d'une personne.
     *
     * @param array<string, mixed> $facts voir PersonnelComplianceProvider::facts()
     * @param array<string, int> $weights
     * @return array{
     *     components: list<array{key: string, status: string, code: string, params: array<string, mixed>,
     *         deadline: ?string, soon: bool, weight: int}>,
     *     status: string, score: ?int, counted: int, compliant: int, late: int, pending: int
     * }
     */
    public static function evaluate(array $facts, string $today, int $days, array $weights): array
    {
        $today = (string) self::day($today);
        $arrival = self::day(is_string($facts['arrival'] ?? null) ? $facts['arrival'] : null);
        $weights = self::normalizeWeights($weights);

        $components = [];
        foreach (self::COMPONENTS as $key) {
            $weight = $weights[$key];
            $component = $weight === 0
                ? ['status' => self::NOT_APPLICABLE, 'code' => 'disabled', 'params' => [], 'deadline' => null]
                : self::component($key, $facts, $today, $days, $arrival);
            $components[] = ['key' => $key] + $component + [
                'soon'   => (bool) ($component['soon'] ?? false),
                'weight' => $weight,
            ];
        }

        return ['components' => $components] + self::summarize($components);
    }

    /**
     * État global et score pondéré : poids des composantes faites sur le poids de celles qui
     * comptent (faites, à faire, en retard).
     *
     * @param list<array{status: string, weight: int}> $components
     * @return array{status: string, score: ?int, counted: int, compliant: int, late: int, pending: int}
     */
    public static function summarize(array $components): array
    {
        $total = 0;
        $done = 0;
        $counts = [self::OK => 0, self::PENDING => 0, self::LATE => 0];
        foreach ($components as $component) {
            if (!isset($counts[$component['status']])) {
                continue;
            }
            $counts[$component['status']]++;
            $total += $component['weight'];
            if ($component['status'] === self::OK) {
                $done += $component['weight'];
            }
        }
        $status = $counts[self::LATE] > 0 ? self::LATE : ($counts[self::PENDING] > 0 ? self::PENDING : self::OK);

        return [
            'status'    => $status,
            'score'     => $total === 0 ? null : (int) round(100 * $done / $total),
            'counted'   => array_sum($counts),
            'compliant' => $counts[self::OK],
            'late'      => $counts[self::LATE],
            'pending'   => $counts[self::PENDING],
        ];
    }

    /**
     * Taux de conformité du personnel : part des personnes sans aucun écart (en retard).
     *
     * @param list<array{status: string}> $people résultats de evaluate()
     * @return array{people: int, compliant: int, late: int, pending: int, rate: ?int}
     */
    public static function staffRate(array $people): array
    {
        $late = 0;
        $pending = 0;
        foreach ($people as $person) {
            if ($person['status'] === self::LATE) {
                $late++;
            } elseif ($person['status'] === self::PENDING) {
                $pending++;
            }
        }
        $count = count($people);

        return [
            'people'    => $count,
            'compliant' => $count - $late,
            'late'      => $late,
            'pending'   => $pending,
            'rate'      => $count === 0 ? null : (int) round(100 * ($count - $late) / $count),
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string, soon?: bool}
     */
    private static function component(string $key, array $facts, string $today, int $days, ?string $arrival): array
    {
        return match ($key) {
            self::SCREENING     => self::screening($facts['screening'] ?? [], $today, $days, $arrival),
            self::NDA           => self::nda($facts['nda'] ?? [], $today, $days, $arrival),
            self::TRAINING      => self::training($facts['trainings'] ?? [], $today, $days, $arrival),
            self::CHARTER       => self::charter($facts['charter'] ?? null, $today, $days, $arrival),
            self::POLICIES      => self::policies($facts['policies'] ?? [], $today, $days, $arrival),
            self::ACCESS_REVIEW => self::accessReview($facts['access_review'] ?? [], $today, $arrival),
            default             => self::account($facts['account_checks'] ?? []),
        };
    }

    /**
     * À faire tant que la date limite n'est pas passée, en retard ensuite. Sans date de départ
     * connue (compte sans date de début ni de création), le délai est réputé écoulé.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function due(string $code, ?string $deadline, string $today, array $params = []): array
    {
        return [
            'status'   => $deadline === null || $today > $deadline ? self::LATE : self::PENDING,
            'code'     => $code,
            'params'   => $params,
            'deadline' => $deadline,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function state(string $status, string $code, array $params = [], ?string $deadline = null): array
    {
        return ['status' => $status, 'code' => $code, 'params' => $params, 'deadline' => $deadline];
    }

    /**
     * @param mixed $record array{status?: string, date?: ?string}
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function screening(mixed $record, string $today, int $days, ?string $arrival): array
    {
        $record = is_array($record) ? $record : [];
        $status = (string) ($record['status'] ?? '');
        if ($status === self::SCREENING_DONE) {
            return self::state(self::OK, 'done', ['date' => self::day($record['date'] ?? null)]);
        }
        if ($status === self::SCREENING_NOT_APPLICABLE) {
            return self::state(self::NOT_APPLICABLE, 'not_applicable');
        }

        return self::due('missing', self::deadline($days, $arrival), $today);
    }

    /**
     * @param mixed $record array{date?: ?string, version?: string}
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function nda(mixed $record, string $today, int $days, ?string $arrival): array
    {
        $record = is_array($record) ? $record : [];
        $date = self::day($record['date'] ?? null);
        if ($date !== null) {
            return self::state(self::OK, 'signed', ['date' => $date, 'version' => (string) ($record['version'] ?? '')]);
        }

        return self::due('missing', self::deadline($days, $arrival), $today);
    }

    /**
     * Une entrée par formation obligatoire (sessions regroupées sous leur formation principale).
     *
     * @param mixed $trainings list<array{title: string, status: string, completion_date: ?string,
     *                         assigned: ?string, renewal_months: int}>
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string, soon?: bool}
     */
    private static function training(mixed $trainings, string $today, int $days, ?string $arrival): array
    {
        $trainings = is_array($trainings) ? $trainings : [];
        if ($trainings === []) {
            return self::due('none', self::deadline($days, $arrival), $today);
        }

        $pending = [];
        $pendingDeadline = null;
        $overdue = [];
        $soon = null;
        foreach ($trainings as $training) {
            $title = (string) ($training['title'] ?? '');
            $status = (string) ($training['status'] ?? 'pending');
            if ($status === 'exempted') {
                continue;
            }
            if ($status !== 'completed') {
                $pending[] = $title;
                $deadline = self::deadline($days, $arrival, $training['assigned'] ?? null);
                if ($deadline !== null && ($pendingDeadline === null || $deadline < $pendingDeadline)) {
                    $pendingDeadline = $deadline;
                }
                continue;
            }
            $months = (int) ($training['renewal_months'] ?? 0);
            $completed = self::day($training['completion_date'] ?? null);
            if ($months <= 0 || $completed === null) {
                continue;
            }
            $expiry = date('Y-m-d', (int) strtotime($completed . ' +' . $months . ' months'));
            if ($expiry < $today) {
                $overdue[] = $title;
            } elseif ($expiry <= self::plusDays($today, self::SOON_DAYS) && ($soon === null || $expiry < $soon)) {
                $soon = $expiry;
            }
        }

        if ($overdue !== []) {
            return self::state(self::LATE, 'renewal_overdue', ['titles' => $overdue]);
        }
        if ($pending !== []) {
            return self::due('pending', $pendingDeadline, $today, ['titles' => $pending]);
        }

        return self::state(self::OK, 'done', ['count' => count($trainings)], $soon) + ['soon' => $soon !== null];
    }

    /**
     * @param mixed $charter null si assetsign est absent, sinon array{status: string, date: ?string,
     *                      assigned: ?string, campaign: string} (status : signed, pending, none)
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function charter(mixed $charter, string $today, int $days, ?string $arrival): array
    {
        if (!is_array($charter)) {
            return self::state(self::NOT_TRACKED, 'not_tracked');
        }
        $status = (string) ($charter['status'] ?? 'none');
        if ($status === 'signed') {
            return self::state(self::OK, 'signed', [
                'date'     => self::day($charter['date'] ?? null),
                'campaign' => (string) ($charter['campaign'] ?? ''),
            ]);
        }
        if ($status === 'pending') {
            return self::due(
                'pending',
                self::deadline($days, $arrival, $charter['assigned'] ?? null),
                $today,
                ['campaign' => (string) ($charter['campaign'] ?? '')]
            );
        }

        return self::due('none', self::deadline($days, $arrival), $today);
    }

    /**
     * Campagne en vigueur de chaque politique dont la personne fait partie du public.
     *
     * @param mixed $policies list<array{title: string, version: string, due_date: ?string, acknowledged: ?string}>
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function policies(mixed $policies, string $today, int $days, ?string $arrival): array
    {
        $policies = is_array($policies) ? $policies : [];
        if ($policies === []) {
            return self::state(self::NOT_APPLICABLE, 'none');
        }
        $pending = [];
        $deadline = null;
        foreach ($policies as $policy) {
            if (self::day($policy['acknowledged'] ?? null) !== null) {
                continue;
            }
            $pending[] = (string) ($policy['title'] ?? '');
            // Jamais avant l'échéance de la campagne, ni avant N jours après l'arrivée.
            $limit = self::latest(self::day($policy['due_date'] ?? null), self::deadline($days, $arrival));
            if ($limit !== null && ($deadline === null || $limit < $deadline)) {
                $deadline = $limit;
            }
        }
        if ($pending === []) {
            return self::state(self::OK, 'done', ['count' => count($policies)]);
        }

        return self::due('pending', $deadline, $today, ['titles' => $pending]);
    }

    /**
     * @param mixed $review array{last_decision?: ?string, pending_due?: ?string}
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string, soon?: bool}
     */
    private static function accessReview(mixed $review, string $today, ?string $arrival): array
    {
        $review = is_array($review) ? $review : [];
        $last = self::day($review['last_decision'] ?? null);
        $pendingDue = self::day($review['pending_due'] ?? null);
        if ($pendingDue !== null && $today > $pendingDue) {
            return self::state(self::LATE, 'campaign_late', ['due' => $pendingDue], $pendingDue);
        }
        $next = self::plusMonths($last ?? $arrival, self::REVIEW_MONTHS);
        if ($last !== null && $next !== null && $today <= $next) {
            $soon = $next <= self::plusDays($today, self::SOON_DAYS);

            return self::state(self::OK, 'reviewed', ['date' => $last], $next) + ['soon' => $soon];
        }
        if ($pendingDue !== null) {
            return self::state(self::PENDING, 'campaign_open', ['due' => $pendingDue], $pendingDue);
        }
        if ($last !== null) {
            return self::state(self::LATE, 'outdated', ['date' => $last], $next);
        }

        // Jamais revu : la première revue est attendue dans les 12 mois suivant l'arrivée.
        return self::due('never', $next, $today);
    }

    /**
     * @param mixed $checks list<string> contrôles d'hygiène sérieux levés par le compte
     * @return array{status: string, code: string, params: array<string, mixed>, deadline: ?string}
     */
    private static function account(mixed $checks): array
    {
        $checks = is_array($checks) ? array_values(array_map('strval', $checks)) : [];

        return $checks === []
            ? self::state(self::OK, 'healthy')
            : self::state(self::LATE, 'checks', ['checks' => $checks]);
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        return $b === null || $a > $b ? $a : $b;
    }

    private static function plusDays(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date . ' +' . $days . ' days'));
    }

    private static function plusMonths(?string $date, int $months): ?string
    {
        return $date === null ? null : date('Y-m-d', (int) strtotime($date . ' +' . $months . ' months'));
    }

    /** Partie date (AAAA-MM-JJ) d'une date ou d'un horodatage, null si absente ou invalide. */
    private static function day(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) < 10) {
            return null;
        }
        $day = substr($value, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && $day !== '0000-00-00' ? $day : null;
    }
}

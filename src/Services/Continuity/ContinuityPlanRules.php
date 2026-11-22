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
 * Issue #1083 : règles des plans de continuité et de reprise, sans base ni session : types, statuts,
 * contenu figé et empreinte, date de revue, état des tests, manques d'un plan et synthèse.
 *
 * Le circuit d'approbation reprend les règles de celui des politiques (PolicyApprovalRules : statut
 * d'une version selon les réponses et la règle, version déjà approuvée).
 */
final class ContinuityPlanRules
{
    /** Plan de continuité d'activité (métier). */
    public const TYPE_PCA = 'pca';
    /** Plan de reprise d'activité (métier). */
    public const TYPE_PRA = 'pra';
    /** Plan de continuité informatique. */
    public const TYPE_PCI = 'pci';
    /** Plan de reprise informatique. */
    public const TYPE_PRI = 'pri';

    public const TYPES = [self::TYPE_PCA, self::TYPE_PRA, self::TYPE_PCI, self::TYPE_PRI];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    public const RESULT_PASSED = 'passed';
    public const RESULT_PARTIAL = 'partial';
    public const RESULT_FAILED = 'failed';

    public const RESULTS = [self::RESULT_PASSED, self::RESULT_PARTIAL, self::RESULT_FAILED];

    /** Revue par défaut : tous les 12 mois. */
    public const DEFAULT_REVIEW_MONTHS = 12;
    public const MAX_REVIEW_MONTHS = 60;

    /** Rappel par défaut : 30 jours avant la date de revue. */
    public const DEFAULT_REMINDER_DAYS = 30;
    public const MAX_REMINDER_DAYS = 365;

    /** Un plan testé il y a plus d'un an est à tester de nouveau (comme les scénarios de crise). */
    public const TEST_VALIDITY_DAYS = CrisisScenarioRules::EXERCISE_VALIDITY_DAYS;

    public const REVIEW_NONE = 'none';
    public const REVIEW_OVERDUE = 'overdue';
    public const REVIEW_SOON = 'soon';
    public const REVIEW_OK = 'ok';

    public const GAP_NOT_APPROVED = 'not_approved';
    public const GAP_REVIEW_OVERDUE = 'review_overdue';
    public const GAP_NEVER_TESTED = 'never_tested';
    public const GAP_TEST_OVERDUE = 'test_overdue';
    public const GAP_LAST_TEST_FAILED = 'last_test_failed';
    public const GAP_NO_PROCESS = 'no_process';
    public const GAP_NO_CRISIS_TEAM = 'no_crisis_team';
    public const GAP_NO_PROCEDURE = 'no_procedure';
    /** Un responsable, un approbateur ou un membre de l'astreinte a quitté l'organisation (compte inactif). */
    public const GAP_PERSON_LEFT = 'person_left';

    /** Champs texte du contenu, dans l'ordre du plan. */
    public const TEXT_FIELDS = [
        'scope', 'activation_criteria', 'activation_authority', 'objectives', 'roles', 'procedures',
        'fallback', 'dependencies', 'communication',
    ];

    /** Champs du contenu : figés pendant l'approbation et après (une nouvelle version les rouvre). */
    public const CONTENT_FIELDS = [
        'name', 'plan_type', 'version', 'users_id', 'groups_id_crisis', 'groups_id_oncall',
        'scope', 'activation_criteria', 'activation_authority', 'objectives', 'roles', 'procedures',
        'fallback', 'dependencies', 'communication',
    ];

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** PCA et PRA concernent l'activité (métier), PCI et PRI l'informatique. */
    public static function isItPlan(string $type): bool
    {
        return in_array($type, [self::TYPE_PCI, self::TYPE_PRI], true);
    }

    public static function normalizeResult(string $result): string
    {
        return in_array($result, self::RESULTS, true) ? $result : self::RESULT_PARTIAL;
    }

    /** Périodicité de revue saisie : 0 (réglage par défaut) ou 1 à 60 mois. */
    public static function sanitizeReviewMonths(mixed $value, bool $allowDefault = true): int
    {
        $months = is_numeric($value) ? (int) $value : 0;
        if ($months <= 0) {
            return $allowDefault ? 0 : self::DEFAULT_REVIEW_MONTHS;
        }

        return min($months, self::MAX_REVIEW_MONTHS);
    }

    public static function sanitizeReminderDays(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : self::DEFAULT_REMINDER_DAYS;

        return max(0, min($days, self::MAX_REMINDER_DAYS));
    }

    /** Périodicité appliquée : celle du plan, sinon le réglage par défaut. */
    public static function effectiveReviewMonths(int $planMonths, int $defaultMonths): int
    {
        return $planMonths > 0
            ? self::sanitizeReviewMonths($planMonths, false)
            : self::sanitizeReviewMonths($defaultMonths, false);
    }

    /** Date de la prochaine revue : date d'approbation plus la périodicité (AAAA-MM-JJ). */
    public static function nextReviewDate(string $approvedDate, int $months): ?string
    {
        $day = CrisisScenarioRules::latestDate($approvedDate);
        if ($day === null) {
            return null;
        }
        $date = new \DateTimeImmutable($day);
        $next = $date->modify('+' . max(1, $months) . ' months');

        return $next->format('Y-m-d');
    }

    /** Revue : absente (plan jamais approuvé), dépassée, proche (dans la fenêtre de rappel) ou à jour. */
    public static function reviewStatus(?string $nextReview, string $today, int $reminderDays): string
    {
        $next = CrisisScenarioRules::latestDate($nextReview);
        if ($next === null) {
            return self::REVIEW_NONE;
        }
        if (strcmp($next, $today) < 0) {
            return self::REVIEW_OVERDUE;
        }
        $soon = date('Y-m-d', (int) strtotime($today . ' +' . max(0, $reminderDays) . ' days'));

        return strcmp($next, $soon) <= 0 ? self::REVIEW_SOON : self::REVIEW_OK;
    }

    /** Tests : jamais testé, test de plus d'un an, ou à jour (CrisisScenarioRules::EXERCISE_*). */
    public static function testStatus(?string $lastTest, string $today): string
    {
        return CrisisScenarioRules::exerciseStatus($lastTest, $today);
    }

    /**
     * Dernier test parmi des lignes de test (date la plus récente, puis identifiant le plus grand).
     *
     * @param iterable<array<string, mixed>> $tests
     * @return array<string, mixed>|null
     */
    public static function lastTest(iterable $tests): ?array
    {
        $last = null;
        foreach ($tests as $test) {
            $date = CrisisScenarioRules::latestDate((string) ($test['test_date'] ?? ''));
            if ($date === null) {
                continue;
            }
            if (
                $last === null
                || strcmp($date, (string) $last['test_date']) > 0
                || ($date === (string) $last['test_date'] && (int) $test['id'] > (int) $last['id'])
            ) {
                $last = $test;
                $last['test_date'] = $date;
            }
        }

        return $last;
    }

    /**
     * Le plan a-t-il une version approuvée en vigueur ? Pendant la préparation d'une nouvelle version,
     * la version approuvée précédente reste celle qui s'applique.
     *
     * @param array<string, mixed> $plan
     */
    public static function hasApprovedVersion(array $plan): bool
    {
        return (string) ($plan['status'] ?? '') === self::STATUS_APPROVED
            || CrisisScenarioRules::latestDate((string) ($plan['date_approved'] ?? '')) !== null;
    }

    /** Le contenu est figé pendant l'approbation et une fois le plan approuvé. */
    public static function isContentLocked(string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    /**
     * Champs du contenu que la saisie modifie réellement.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function changedContentFields(array $current, array $input): array
    {
        $changed = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (self::normalizeText($current[$field] ?? '') !== self::normalizeText($input[$field])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Empreinte SHA-256 d'un contenu figé (ordre des clés canonique). Deux contenus identiques ont
     * la même empreinte, quel que soit l'ordre de leurs éléments.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', (string) json_encode(self::canonical($snapshot), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (array_is_list($value)) {
            $items = array_map(
                static fn ($item) => is_array($item) ? self::canonical($item) : self::normalizeText($item),
                $value
            );
            usort($items, static fn ($a, $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

            return $items;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? self::canonical($item) : self::normalizeText($item);
        }

        return $value;
    }

    private static function normalizeText(mixed $value): string
    {
        return trim(str_replace("\r\n", "\n", (string) $value));
    }

    /**
     * Manques d'un plan, dans l'ordre d'affichage.
     *
     * @param array<string, mixed> $plan ligne du plan
     * @param array{test_status: string, last_result: string, review_status: string, process_count: int,
     *     person_left: bool} $state
     * @return list<string>
     */
    public static function gaps(array $plan, array $state): array
    {
        $gaps = [];
        if (!self::hasApprovedVersion($plan)) {
            $gaps[] = self::GAP_NOT_APPROVED;
        }
        if ($state['review_status'] === self::REVIEW_OVERDUE) {
            $gaps[] = self::GAP_REVIEW_OVERDUE;
        }
        if ($state['test_status'] === CrisisScenarioRules::EXERCISE_NEVER) {
            $gaps[] = self::GAP_NEVER_TESTED;
        } elseif ($state['test_status'] === CrisisScenarioRules::EXERCISE_OVERDUE) {
            $gaps[] = self::GAP_TEST_OVERDUE;
        }
        if ($state['last_result'] === self::RESULT_FAILED) {
            $gaps[] = self::GAP_LAST_TEST_FAILED;
        }
        if ($state['process_count'] === 0) {
            $gaps[] = self::GAP_NO_PROCESS;
        }
        if ((int) ($plan['groups_id_crisis'] ?? 0) <= 0) {
            $gaps[] = self::GAP_NO_CRISIS_TEAM;
        }
        if (self::normalizeText($plan['procedures'] ?? '') === '') {
            $gaps[] = self::GAP_NO_PROCEDURE;
        }
        if ($state['person_left']) {
            $gaps[] = self::GAP_PERSON_LEFT;
        }

        return $gaps;
    }

    /**
     * Indicateurs de la rubrique : plans non approuvés, revue dépassée, jamais testés ou testés il y a
     * plus d'un an, processus critiques sans plan.
     *
     * @param list<array{gaps: list<string>}> $plans plans présentés (hors corbeille)
     * @param list<array<string, mixed>> $processes processus lisibles (id, rto_hours)
     * @param array<int, int> $planCount nombre de plans par processus
     * @return array{plans: int, not_approved: int, review_overdue: int, untested: int,
     *     critical_uncovered: int, approved: int, tested: int}
     */
    public static function summary(array $plans, array $processes, array $planCount): array
    {
        $has = static fn (string ...$gaps): int => count(array_filter(
            $plans,
            static fn (array $plan): bool => array_intersect($gaps, $plan['gaps']) !== []
        ));

        return [
            'plans'              => count($plans),
            'not_approved'       => $has(self::GAP_NOT_APPROVED),
            'review_overdue'     => $has(self::GAP_REVIEW_OVERDUE),
            'untested'           => $has(self::GAP_NEVER_TESTED, self::GAP_TEST_OVERDUE),
            'critical_uncovered' => count(CrisisScenarioRules::uncoveredCriticalProcesses($processes, $planCount)),
            'approved'           => count($plans) - $has(self::GAP_NOT_APPROVED),
            'tested'             => count($plans) - $has(self::GAP_NEVER_TESTED, self::GAP_TEST_OVERDUE),
        ];
    }
}

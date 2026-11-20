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

use GlpiPlugin\Grcmanager\Services\Responsibility\ResponsibilityRules;

/**
 * Issue #773 : règles du programme d'audit interne selon l'ISO 19011 (PHP pur, testé seul).
 *
 * - Programme pluriannuel : matrice processus (activités du SMSI de la matrice des
 *   responsabilités) x années du cycle de certification, couverture calculée depuis les audits.
 * - Indépendance : un auditeur qui réalise ou approuve un processus audité (directement ou par un
 *   de ses groupes) est en conflit ; l'audit ne démarre ni ne se termine tant qu'un conflit n'a
 *   pas de justification.
 * - Compétence : formations qualifiantes suivies par l'auditeur, à jour de leur renouvellement.
 */
final class ProgrammeRules
{
    public const CYCLE_LENGTH = 3;
    public const MAX_PAST_YEARS = 10;
    public const MAX_FUTURE_YEARS = 2;

    /** État d'une case (processus, année) ou d'un processus sur le cycle. */
    public const STATE_DONE = 'done';
    public const STATE_PLANNED = 'planned';
    public const STATE_LATE = 'late';
    public const STATE_NONE = 'none';

    /** Rôles de la matrice des responsabilités qui empêchent d'auditer le processus. */
    public const CONFLICT_ROLES = [ResponsibilityRules::ROLE_RESPONSIBLE, ResponsibilityRules::ROLE_ACCOUNTABLE];

    /** Statuts d'audit qui exigent l'indépendance de l'équipe (démarrer, terminer). */
    public const GUARDED_STATUSES = ['in_progress', 'completed'];

    public const COMPETENCE_QUALIFIED = 'qualified';
    public const COMPETENCE_EXPIRED = 'expired';
    public const COMPETENCE_MISSING = 'missing';
    public const COMPETENCE_NOT_CONFIGURED = 'not_configured';

    public const MIN_JUSTIFICATION_LENGTH = 10;

    /** Première année du cycle : celle choisie, sinon l'année précédente, bornée. */
    public static function cycleStart(mixed $value, int $currentYear): int
    {
        $start = is_numeric($value) ? (int) $value : $currentYear - 1;

        return max($currentYear - self::MAX_PAST_YEARS, min($currentYear + self::MAX_FUTURE_YEARS, $start));
    }

    /** @return list<int> */
    public static function cycleYears(int $start): array
    {
        return range($start, $start + self::CYCLE_LENGTH - 1);
    }

    /** @return array{0: string, 1: string} premier et dernier jour du cycle */
    public static function cycleBounds(int $start): array
    {
        return [sprintf('%04d-01-01', $start), sprintf('%04d-12-31', $start + self::CYCLE_LENGTH - 1)];
    }

    /**
     * Un processus peut-il être visé par l'audit ? Même entité, processus visible des
     * sous-entités d'une entité parente, ou processus d'une sous-entité d'un audit récursif.
     *
     * @param list<int> $auditChain entité de l'audit puis ses parents (PerimeterRules::chain())
     * @param list<int> $auditSubtree entité de l'audit et ses sous-entités (PerimeterRules::subtree())
     */
    public static function processFitsAudit(
        int $processEntity,
        bool $processRecursive,
        array $auditChain,
        array $auditSubtree,
        bool $auditRecursive
    ): bool {
        if ($processEntity === ($auditChain[0] ?? -1)) {
            return true;
        }
        if ($processRecursive && in_array($processEntity, $auditChain, true)) {
            return true;
        }

        return $auditRecursive && in_array($processEntity, $auditSubtree, true);
    }

    /** Date retenue pour un audit : réelle, sinon prévue. */
    public static function auditDate(?string $actual, ?string $planned): string
    {
        return (string) (($actual ?? '') !== '' ? $actual : ($planned ?? ''));
    }

    /**
     * État d'un ensemble d'audits (non annulés) : réalisé si l'un est terminé, prévu si l'un est
     * en cours ou à venir, en retard si seuls restent des audits prévus à une date passée.
     *
     * @param list<array{status: string, date: string}> $audits
     */
    public static function state(array $audits, string $today): string
    {
        $state = self::STATE_NONE;
        foreach ($audits as $audit) {
            if ($audit['status'] === 'completed') {
                return self::STATE_DONE;
            }
            if ($audit['status'] === 'in_progress' || $audit['date'] >= $today) {
                $state = self::STATE_PLANNED;
            } elseif ($state === self::STATE_NONE) {
                $state = self::STATE_LATE;
            }
        }

        return $state;
    }

    /**
     * Matrice processus x années du cycle.
     *
     * @param array<int, string> $processes id => libellé, dans l'ordre d'affichage
     * @param list<array{id: int, title: string, status: string, date: string, processes: list<int>}> $audits
     * @return array{years: list<int>, rows: list<array<string, mixed>>, total: int, covered: int,
     *               planned: int, late: int, uncovered: int, percent: int, unassigned: list<array<string, mixed>>}
     */
    public static function matrix(array $processes, array $audits, int $start, string $today): array
    {
        $years = self::cycleYears($start);
        $byCell = [];
        $unassigned = [];
        foreach ($audits as $audit) {
            if ($audit['status'] === 'cancelled' || $audit['date'] === '') {
                continue;
            }
            $year = (int) substr($audit['date'], 0, 4);
            if (!in_array($year, $years, true)) {
                continue;
            }
            if ($audit['processes'] === []) {
                $unassigned[] = $audit;
            }
            foreach ($audit['processes'] as $processId) {
                $byCell[$processId][$year][] = $audit;
            }
        }

        $rows = [];
        $counts = [self::STATE_DONE => 0, self::STATE_PLANNED => 0, self::STATE_LATE => 0, self::STATE_NONE => 0];
        foreach ($processes as $id => $label) {
            $cells = [];
            $all = [];
            foreach ($years as $year) {
                $cellAudits = $byCell[$id][$year] ?? [];
                $all = array_merge($all, $cellAudits);
                $cells[$year] = ['state' => self::state($cellAudits, $today), 'audits' => $cellAudits];
            }
            $state = self::state($all, $today);
            $counts[$state]++;
            $rows[] = ['id' => $id, 'label' => $label, 'cells' => $cells, 'state' => $state];
        }
        // Processus sans audit réalisé en tête : ce sont les alertes du programme.
        $order = [self::STATE_NONE => 0, self::STATE_LATE => 1, self::STATE_PLANNED => 2, self::STATE_DONE => 3];
        $rank = array_flip(array_keys($processes));
        usort($rows, static fn (array $a, array $b): int => [$order[$a['state']], $rank[$a['id']]]
            <=> [$order[$b['state']], $rank[$b['id']]]);

        $total = count($rows);

        return [
            'years'      => $years,
            'rows'       => $rows,
            'total'      => $total,
            'covered'    => $counts[self::STATE_DONE],
            'planned'    => $counts[self::STATE_PLANNED],
            'late'       => $counts[self::STATE_LATE],
            'uncovered'  => $counts[self::STATE_NONE],
            'percent'    => $total > 0 ? (int) floor($counts[self::STATE_DONE] * 100 / $total) : 0,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Conflits d'indépendance : chaque auditeur qui réalise ou approuve un processus audité, en
     * son nom ou par un de ses groupes.
     *
     * @param list<int> $auditors
     * @param array<int, list<int>> $groupsOfUser utilisateur => groupes
     * @param array<int, list<array{itemtype: string, items_id: int, role: string}>> $assignments
     *        processus => affectations
     * @return list<array{users_id: int, process_id: int, role: string, groups_id: int}>
     */
    public static function conflicts(array $auditors, array $groupsOfUser, array $assignments): array
    {
        $conflicts = [];
        foreach (array_unique(array_filter($auditors, static fn (int $id): bool => $id > 0)) as $userId) {
            $groups = $groupsOfUser[$userId] ?? [];
            foreach ($assignments as $processId => $rows) {
                foreach ($rows as $row) {
                    if (!in_array($row['role'], self::CONFLICT_ROLES, true)) {
                        continue;
                    }
                    $direct = $row['itemtype'] === 'User' && $row['items_id'] === $userId;
                    $viaGroup = $row['itemtype'] === 'Group' && in_array($row['items_id'], $groups, true);
                    if ($direct || $viaGroup) {
                        $conflicts[self::conflictKey($userId, $processId)] ??= [
                            'users_id'   => $userId,
                            'process_id' => $processId,
                            'role'       => $row['role'],
                            'groups_id'  => $direct ? 0 : $row['items_id'],
                        ];
                    }
                }
            }
        }

        return array_values($conflicts);
    }

    public static function conflictKey(int $userId, int $processId): string
    {
        return $userId . ':' . $processId;
    }

    /**
     * Conflits sans justification.
     *
     * @param list<array{users_id: int, process_id: int, role: string, groups_id: int}> $conflicts
     * @param list<string> $justifiedKeys
     * @return list<array{users_id: int, process_id: int, role: string, groups_id: int}>
     */
    public static function unjustified(array $conflicts, array $justifiedKeys): array
    {
        return array_values(array_filter(
            $conflicts,
            static fn (array $conflict): bool => !in_array(
                self::conflictKey($conflict['users_id'], $conflict['process_id']),
                $justifiedKeys,
                true
            )
        ));
    }

    /**
     * Le changement demandé exige-t-il une équipe indépendante ? Oui quand l'audit passe à « En
     * cours » ou « Terminé », ou change d'auditeur dans l'un de ces statuts.
     */
    public static function isGuardedChange(string $oldStatus, string $newStatus, bool $auditorChanged): bool
    {
        if (!in_array($newStatus, self::GUARDED_STATUSES, true)) {
            return false;
        }

        return $newStatus !== $oldStatus || $auditorChanged;
    }

    public static function cleanJustification(mixed $value): string
    {
        return trim(str_replace("\r\n", "\n", is_scalar($value) ? (string) $value : ''));
    }

    public static function isValidJustification(string $text): bool
    {
        return mb_strlen($text) >= self::MIN_JUSTIFICATION_LENGTH;
    }

    /**
     * Compétence d'un auditeur d'après ses formations qualifiantes : réalisée (ou dispensée) sans
     * échec à l'évaluation, et à jour si la formation demande un renouvellement.
     *
     * @param list<array{title: string, completion_status: string, completion_date: ?string,
     *                   assessment_result: string, renewal_period_months: int}> $trainings
     *        formations qualifiantes de l'auditeur
     * @return array{status: string, trainings: list<array<string, mixed>>}
     */
    public static function competence(array $trainings, bool $configured, string $today): array
    {
        if (!$configured) {
            return ['status' => self::COMPETENCE_NOT_CONFIGURED, 'trainings' => []];
        }
        $status = self::COMPETENCE_MISSING;
        $kept = [];
        foreach ($trainings as $training) {
            if (
                !in_array($training['completion_status'], ['completed', 'exempted'], true)
                || $training['assessment_result'] === 'failed'
            ) {
                continue;
            }
            $date = (string) ($training['completion_date'] ?? '');
            $months = (int) $training['renewal_period_months'];
            $valid = true;
            if ($training['completion_status'] === 'completed' && $months > 0) {
                $limit = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $valid = $limit !== false && $limit->modify('+' . $months . ' months')->format('Y-m-d') >= $today;
            }
            $kept[] = $training + ['valid' => $valid];
            if ($valid) {
                $status = self::COMPETENCE_QUALIFIED;
            } elseif ($status === self::COMPETENCE_MISSING) {
                $status = self::COMPETENCE_EXPIRED;
            }
        }

        return ['status' => $status, 'trainings' => $kept];
    }
}

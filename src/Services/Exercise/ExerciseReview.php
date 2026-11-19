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

namespace GlpiPlugin\Grcmanager\Services\Exercise;

/**
 * Issue #732 : règles du bilan après exercice, sans dépendance à GLPI. Objectifs évalués
 * « atteint », « partiel » ou « non atteint », gravité des constats, avancement du plan
 * d'amélioration et conditions de validation du bilan.
 */
final class ExerciseReview
{
    public const OUTCOME_MET = 'met';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_NOT_MET = 'not_met';
    public const OUTCOMES = [self::OUTCOME_MET, self::OUTCOME_PARTIAL, self::OUTCOME_NOT_MET];

    public const SEVERITIES = ['minor', 'major', 'critical'];

    public const ACTION_DONE = 'done';
    public const ACTION_OVERDUE = 'overdue';
    public const ACTION_OPEN = 'open';
    public const ACTION_HIDDEN = 'hidden';
    public const ACTION_MISSING = 'missing';

    public const EVENT_VALIDATED = 'validated';
    public const EVENT_REOPENED = 'reopened';

    public const REFUSED_NOT_ENDED = 'not_ended';
    public const REFUSED_VALIDATED = 'validated';
    public const REFUSED_UNEVALUATED = 'unevaluated';
    public const REFUSED_NO_OBJECTIVE = 'no_objective';

    /** Objectifs saisis en une fois au lancement : au plus 20, une ligne chacun. */
    public const MAX_OBJECTIVES = 20;

    public const MAX_NAME_LENGTH = 255;

    public static function normalizeOutcome(string $outcome): string
    {
        return in_array($outcome, self::OUTCOMES, true) ? $outcome : '';
    }

    public static function normalizeSeverity(string $severity): string
    {
        return in_array($severity, self::SEVERITIES, true) ? $severity : 'minor';
    }

    /**
     * Objectifs saisis un par ligne : lignes vides et doublons écartés, longueur bornée.
     *
     * @return list<string>
     */
    public static function splitObjectives(string $text): array
    {
        $objectives = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::shorten(trim($line));
            if ($line !== '' && !in_array($line, $objectives, true)) {
                $objectives[] = $line;
            }
        }

        return array_slice($objectives, 0, self::MAX_OBJECTIVES);
    }

    public static function shorten(string $text): string
    {
        return mb_substr(trim($text), 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Synthèse des objectifs : nombre atteints, partiels, non atteints et non évalués.
     *
     * @param list<string> $outcomes résultat de chaque objectif ('' : non évalué)
     * @return array{total: int, met: int, partial: int, not_met: int, pending: int}
     */
    public static function objectivesSummary(array $outcomes): array
    {
        $summary = ['total' => count($outcomes), 'met' => 0, 'partial' => 0, 'not_met' => 0, 'pending' => 0];
        foreach ($outcomes as $outcome) {
            $outcome = self::normalizeOutcome($outcome);
            $summary[$outcome === '' ? 'pending' : $outcome]++;
        }

        return $summary;
    }

    /**
     * Le bilan ne se valide qu'une fois l'exercice terminé, avec au moins un objectif et chaque
     * objectif évalué.
     *
     * @param list<string> $outcomes
     * @return string motif du refus, '' si la validation est possible
     */
    public static function validationRefusal(bool $ended, bool $validated, array $outcomes): string
    {
        if ($validated) {
            return self::REFUSED_VALIDATED;
        }
        if (!$ended) {
            return self::REFUSED_NOT_ENDED;
        }
        if ($outcomes === []) {
            return self::REFUSED_NO_OBJECTIVE;
        }

        return self::objectivesSummary($outcomes)['pending'] > 0 ? self::REFUSED_UNEVALUATED : '';
    }

    /**
     * Avancement d'une action d'amélioration : faite, en retard (échéance passée sans être faite)
     * ou en cours.
     */
    public static function actionState(bool $closed, ?string $dueDate, string $today): string
    {
        if ($closed) {
            return self::ACTION_DONE;
        }
        if ($dueDate !== null && $dueDate !== '' && substr($dueDate, 0, 10) < substr($today, 0, 10)) {
            return self::ACTION_OVERDUE;
        }

        return self::ACTION_OPEN;
    }

    /**
     * Synthèse du plan d'amélioration : constats, actions décidées, faites et en retard.
     *
     * @param list<string> $states état de l'action de chaque constat ('' : aucune action)
     * @return array{findings: int, actions: int, done: int, overdue: int, without_action: int}
     */
    public static function planSummary(array $states): array
    {
        $summary = ['findings' => count($states), 'actions' => 0, 'done' => 0, 'overdue' => 0, 'without_action' => 0];
        foreach ($states as $state) {
            if ($state === '') {
                $summary['without_action']++;
                continue;
            }
            $summary['actions']++;
            if ($state === self::ACTION_DONE) {
                $summary['done']++;
            } elseif ($state === self::ACTION_OVERDUE) {
                $summary['overdue']++;
            }
        }

        return $summary;
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Readiness;

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;

/**
 * Issue #619 : parcours de préparation à la certification ISO 27001. Règles pures (sans GLPI) :
 * étapes ordonnées selon le cycle planifier, faire, vérifier, agir, état de chaque étape à partir
 * de comptages déjà calculés par les modules du plugin, score global et prochaine étape.
 */
final class CertificationReadinessRules
{
    public const STATUS_DONE    = 'done';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_TODO    = 'todo';
    /** Étape que la personne ne peut pas évaluer (droit de lecture manquant) : hors du score. */
    public const STATUS_LOCKED  = 'locked';

    public const STATUSES = [self::STATUS_DONE, self::STATUS_PARTIAL, self::STATUS_TODO, self::STATUS_LOCKED];

    public const PHASES = ['plan', 'do', 'check', 'act'];

    /** Période (mois) dans laquelle une formation, un exercice, un audit ou une revue compte. */
    public const RECENT_MONTHS = 12;

    /** Part minimale (%) des participants ayant suivi leur formation pour une étape « faite ». */
    public const TRAINING_RATE_TARGET = 80;

    /** Couverture (%) du programme d'audit attendue avant la certification. */
    public const COVERAGE_TARGET = 100;

    /**
     * Étapes, dans l'ordre du parcours. `clauses` : numéros affichés ; `requirements` : exigences du
     * SMSI (écran « Exigences du SMSI ») dont le statut déclaré est rappelé.
     *
     * @var array<string, array{phase: string, clauses: string, requirements: list<string>}>
     */
    public const STEPS = [
        'scope'             => ['phase' => 'plan', 'clauses' => '4.3', 'requirements' => ['4.3']],
        'context'           => ['phase' => 'plan', 'clauses' => '4.1, 4.2', 'requirements' => ['4.1', '4.2']],
        'policy'            => ['phase' => 'plan', 'clauses' => '5.2', 'requirements' => ['5.2']],
        'roles'             => ['phase' => 'plan', 'clauses' => '5.3', 'requirements' => ['5.3']],
        'risk_assessment'   => ['phase' => 'plan', 'clauses' => '6.1.2, 8.2', 'requirements' => ['6.1.2', '8.2']],
        'risk_treatment'    => ['phase' => 'plan', 'clauses' => '6.1.3, 8.3', 'requirements' => ['6.1.3', '8.3']],
        'soa'               => ['phase' => 'plan', 'clauses' => '6.1.3 d)', 'requirements' => []],
        'objectives'        => ['phase' => 'plan', 'clauses' => '6.2', 'requirements' => ['6.2']],
        'awareness'         => ['phase' => 'do', 'clauses' => '7.2, 7.3', 'requirements' => ['7.2', '7.3']],
        'exercises'         => ['phase' => 'do', 'clauses' => 'A.5.29, A.5.30', 'requirements' => []],
        'internal_audit'    => ['phase' => 'check', 'clauses' => '9.2', 'requirements' => ['9.2.1', '9.2.2']],
        'management_review' => [
            'phase' => 'check', 'clauses' => '9.3', 'requirements' => ['9.3.1', '9.3.2', '9.3.3'],
        ],
        'nonconformities'   => ['phase' => 'act', 'clauses' => '10.2', 'requirements' => ['10.2']],
    ];

    /** Périmètre : exigence 4.3 déclarée conforme, ou commencée (statut, document joint). */
    public static function scope(string $declared, int $documents): string
    {
        if ($declared === IsmsClauseCatalog::STATUS_COMPLIANT) {
            return self::STATUS_DONE;
        }

        return $declared === IsmsClauseCatalog::STATUS_IN_PROGRESS || $documents > 0
            ? self::STATUS_PARTIAL
            : self::STATUS_TODO;
    }

    /** Contexte : enjeux et parties intéressées, question climatique tranchée, aucune revue dépassée. */
    public static function context(int $issues, int $parties, bool $climate, int $overdue): string
    {
        if ($issues === 0 && $parties === 0) {
            return self::STATUS_TODO;
        }

        return $issues > 0 && $parties > 0 && $climate && $overdue === 0 ? self::STATUS_DONE : self::STATUS_PARTIAL;
    }

    /** Politique : au moins une politique approuvée, aucune revue dépassée. */
    public static function policy(int $approved, int $drafts, int $reviewOverdue): string
    {
        if ($approved === 0) {
            return $drafts > 0 ? self::STATUS_PARTIAL : self::STATUS_TODO;
        }

        return $reviewOverdue > 0 ? self::STATUS_PARTIAL : self::STATUS_DONE;
    }

    /** Rôles : activités de la matrice des responsabilités, sans alerte forte (personne, cumul...). */
    public static function roles(int $activities, int $highAlerts): string
    {
        if ($activities === 0) {
            return self::STATUS_TODO;
        }

        return $highAlerts > 0 ? self::STATUS_PARTIAL : self::STATUS_DONE;
    }

    /** Appréciation des risques : risques ouverts, chacun avec un propriétaire et revu à temps. */
    public static function riskAssessment(int $openRisks, int $withoutOwner, int $reviewOverdue): string
    {
        if ($openRisks === 0) {
            return self::STATUS_TODO;
        }

        return $withoutOwner + $reviewOverdue > 0 ? self::STATUS_PARTIAL : self::STATUS_DONE;
    }

    /** Traitement : décision sur chaque risque élevé, acceptations approuvées, actions à l'heure. */
    public static function riskTreatment(
        int $openRisks,
        int $withoutDecision,
        int $acceptedWithoutApproval,
        int $actionsOverdue
    ): string {
        if ($openRisks === 0) {
            return self::STATUS_TODO;
        }

        return $withoutDecision + $acceptedWithoutApproval + $actionsOverdue > 0
            ? self::STATUS_PARTIAL
            : self::STATUS_DONE;
    }

    /**
     * Déclaration d'applicabilité : version approuvée à jour ; modifiée depuis ou en attente
     * d'approbation : partielle.
     *
     * @param string $state état de SoaApprovalLogic::currentState() (up_to_date, changed, never_approved)
     */
    public static function soa(string $state, bool $pending): string
    {
        return match (true) {
            $state === 'up_to_date'                => self::STATUS_DONE,
            $state === 'changed' || $pending       => self::STATUS_PARTIAL,
            default                                => self::STATUS_TODO,
        };
    }

    /** Objectifs : au moins un objectif, chacun avec au moins une mesure. */
    public static function objectives(int $total, int $measured): string
    {
        if ($total === 0) {
            return self::STATUS_TODO;
        }

        return $measured >= $total ? self::STATUS_DONE : self::STATUS_PARTIAL;
    }

    /**
     * Sensibilisation : une formation réalisée dans la période, et assez de participants l'ayant
     * suivie (null : aucun participant inscrit).
     */
    public static function awareness(int $trainings, int $deliveredRecently, ?int $completionRate): string
    {
        if ($trainings === 0) {
            return self::STATUS_TODO;
        }
        if ($deliveredRecently === 0) {
            return self::STATUS_PARTIAL;
        }

        return $completionRate === null || $completionRate >= self::TRAINING_RATE_TARGET
            ? self::STATUS_DONE
            : self::STATUS_PARTIAL;
    }

    /** Exercices : un exercice dont le bilan est validé dans la période. */
    public static function exercises(int $total, int $recent, int $validatedRecently): string
    {
        if ($validatedRecently > 0) {
            return self::STATUS_DONE;
        }

        return $total > 0 || $recent > 0 ? self::STATUS_PARTIAL : self::STATUS_TODO;
    }

    /** Audit interne : un audit terminé dans la période et un programme qui couvre tout. */
    public static function internalAudit(bool $recentCompleted, int $audits, int $coveragePercent): string
    {
        if ($recentCompleted && $coveragePercent >= self::COVERAGE_TARGET) {
            return self::STATUS_DONE;
        }

        return $recentCompleted || $audits > 0 || $coveragePercent > 0 ? self::STATUS_PARTIAL : self::STATUS_TODO;
    }

    /** Revue de direction : une revue terminée dans la période, avec son dossier préparé. */
    public static function managementReview(bool $recentCompleted, int $reviews, bool $dossier): string
    {
        if ($recentCompleted && $dossier) {
            return self::STATUS_DONE;
        }

        return $recentCompleted || $reviews > 0 ? self::STATUS_PARTIAL : self::STATUS_TODO;
    }

    /**
     * Non-conformités : aucune en retard ni close sans action corrective, aucune encore ouverte.
     * Aucune non-conformité enregistrée : rien à traiter.
     */
    public static function nonconformities(int $open, int $overdue, int $closedWithoutAction): string
    {
        return $open + $overdue + $closedWithoutAction > 0 ? self::STATUS_PARTIAL : self::STATUS_DONE;
    }

    /**
     * Score sur 100 : une étape faite compte entière, une étape partielle à moitié ; les étapes
     * hors des droits de la personne ne comptent pas. Null si aucune étape n'est évaluable.
     *
     * @param list<string> $statuses
     */
    public static function score(array $statuses): ?int
    {
        $points = 0;
        $evaluated = 0;
        foreach ($statuses as $status) {
            if (!in_array($status, [self::STATUS_DONE, self::STATUS_PARTIAL, self::STATUS_TODO], true)) {
                continue;
            }
            $evaluated++;
            $points += match ($status) {
                self::STATUS_DONE    => 2,
                self::STATUS_PARTIAL => 1,
                default              => 0,
            };
        }

        return $evaluated === 0 ? null : (int) round($points * 50 / $evaluated);
    }

    /** Couleur Tabler du score (rouge, orange, vert). */
    public static function color(?int $score): string
    {
        return match (true) {
            $score === null => 'secondary',
            $score >= 80    => 'green',
            $score >= 50    => 'orange',
            default         => 'red',
        };
    }

    /**
     * @param array<int|string, string> $statuses statuts (par étape ou en liste)
     * @return array{done: int, partial: int, todo: int, locked: int}
     */
    public static function counts(array $statuses): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($statuses as $status) {
            $counts[in_array($status, self::STATUSES, true) ? $status : self::STATUS_TODO]++;
        }

        return $counts;
    }

    /**
     * Première étape du parcours qui n'est pas faite (et que la personne peut évaluer).
     *
     * @param array<string, string> $statuses étape => statut, dans l'ordre du parcours
     */
    public static function nextStep(array $statuses): ?string
    {
        foreach ($statuses as $key => $status) {
            if ($status === self::STATUS_TODO || $status === self::STATUS_PARTIAL) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Statuts déclarés des exigences du SMSI d'une étape : nombre de conformes sur le total connu.
     *
     * @param list<string> $codes
     * @param array<string, string> $declared code => statut
     * @return array{compliant: int, total: int}
     */
    public static function declared(array $codes, array $declared): array
    {
        $compliant = 0;
        $total = 0;
        foreach ($codes as $code) {
            if (!isset($declared[$code])) {
                continue;
            }
            $total++;
            if ($declared[$code] === IsmsClauseCatalog::STATUS_COMPLIANT) {
                $compliant++;
            }
        }

        return ['compliant' => $compliant, 'total' => $total];
    }
}

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

    // Issue #761 : date cible, échéances conseillées, cycle de maintien et seuils réglables.

    /**
     * Délai conseillé (jours avant la date cible de l'audit) pour finir chaque étape : le socle
     * (périmètre, contexte, politique, rôles, risques) d'abord, puis la SoA et les objectifs, la
     * formation et l'exercice, l'audit interne, la revue de direction qui s'appuie sur ses
     * résultats, et enfin le traitement des non-conformités qu'ils ont relevées.
     *
     * @var array<string, int>
     */
    public const LEAD_DAYS = [
        'scope'             => 150,
        'context'           => 150,
        'policy'            => 120,
        'roles'             => 120,
        'risk_assessment'   => 120,
        'risk_treatment'    => 90,
        'soa'               => 90,
        'objectives'        => 90,
        'competence'        => 90,
        'awareness'         => 60,
        'exercises'         => 60,
        'internal_audit'    => 45,
        'management_review' => 30,
        'nonconformities'   => 15,
    ];

    /** Une étape non faite dont l'échéance conseillée tombe dans ce délai (jours) est « à risque ». */
    public const ALERT_DAYS = 14;

    /** Étapes à refaire chaque année une fois certifié (cycle de maintien), dans l'ordre du parcours. */
    public const ANNUAL_STEPS = ['risk_assessment', 'soa', 'internal_audit', 'management_review'];

    /** Durée (années) du cycle de certification : deux audits de surveillance, puis le renouvellement. */
    public const CYCLE_YEARS = 3;

    public const DEADLINE_NONE = 'none';
    public const DEADLINE_ON_TRACK = 'on_track';
    public const DEADLINE_AT_RISK = 'at_risk';
    public const DEADLINE_LATE = 'late';

    public const MODE_PREPARATION = 'preparation';
    public const MODE_MAINTENANCE = 'maintenance';

    /** Origine de la date cible : saisie, audit ou événement planifié, ou cycle de maintien. */
    public const TARGET_MANUAL = 'manual';
    public const TARGET_AUDIT = 'audit';
    public const TARGET_EVENT = 'event';
    public const TARGET_CYCLE = 'cycle';

    /** Date AAAA-MM-JJ valide, sinon null (saisie vide ou incorrecte). */
    public static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = substr(trim($value), 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** Seuil en pourcentage, entre 1 et 100 ; valeur par défaut si la saisie n'est pas un nombre. */
    public static function normalizePercent(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, min(100, (int) $value));
    }

    private static function shift(string $date, string $modifier): string
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable('today');

        return $base->modify($modifier)->format('Y-m-d');
    }

    /** Échéance conseillée d'une étape : la date cible moins le délai de l'étape. */
    public static function advisedDeadline(string $target, string $step): string
    {
        return self::shift($target, '-' . (self::LEAD_DAYS[$step] ?? 0) . ' days');
    }

    /**
     * État d'une échéance : aucune (étape faite, hors des droits ou sans date cible), en retard
     * (échéance passée), à risque (échéance dans les ALERT_DAYS jours) ou dans les temps.
     */
    public static function deadlineState(?string $deadline, string $status, string $today): string
    {
        if ($deadline === null || !in_array($status, [self::STATUS_TODO, self::STATUS_PARTIAL], true)) {
            return self::DEADLINE_NONE;
        }
        if ($deadline < $today) {
            return self::DEADLINE_LATE;
        }

        return $deadline <= self::shift($today, '+' . self::ALERT_DAYS . ' days')
            ? self::DEADLINE_AT_RISK
            : self::DEADLINE_ON_TRACK;
    }

    /**
     * Cycle de maintien après la certification : audits de surveillance annuels, renouvellement à
     * trois ans (dates anniversaires de la certification).
     *
     * @return list<array{type: string, date: string}>
     */
    public static function maintenanceCycle(string $certified): array
    {
        $cycle = [];
        for ($year = 1; $year <= self::CYCLE_YEARS; $year++) {
            $cycle[] = [
                'type' => $year < self::CYCLE_YEARS ? 'surveillance_' . $year : 'renewal',
                'date' => self::shift($certified, '+' . $year . ' years'),
            ];
        }

        return $cycle;
    }

    /**
     * Prochain audit du cycle de maintien à partir d'aujourd'hui ; après le renouvellement, le cycle
     * suivant recommence à partir de sa date.
     *
     * @return array{type: string, date: string}|null
     */
    public static function nextCycleAudit(?string $certified, string $today): ?array
    {
        if ($certified === null || $certified > $today) {
            return null;
        }
        foreach (self::maintenanceCycle(self::currentCycleStart($certified, $today)) as $audit) {
            if ($audit['date'] >= $today) {
                return $audit;
            }
        }

        return null;
    }

    /**
     * Début du cycle en cours : la date de certification, ou celle du dernier renouvellement passé.
     */
    public static function currentCycleStart(string $certified, string $today): string
    {
        $start = $certified;
        // Borné : une date très ancienne ne boucle pas sans fin.
        for ($guard = 0; $guard < 50; $guard++) {
            $renewal = self::shift($start, '+' . self::CYCLE_YEARS . ' years');
            if ($renewal >= $today) {
                break;
            }
            $start = $renewal;
        }

        return $start;
    }

    /**
     * Date cible retenue, dans cet ordre : la date saisie (si elle n'est pas passée), le prochain
     * audit de certification planifié (audit ou événement du calendrier), sinon le prochain audit
     * du cycle de maintien.
     *
     * @param list<array{date: string, source: string, type: string, name: string, url: string,
     *     users_id: int}> $planned
     * @param array{type: string, date: string}|null $cycleNext
     * @return array{date: string, source: string, type: string, name: string, url: string,
     *     users_id: int}|null
     */
    public static function pickTarget(?string $manual, array $planned, ?array $cycleNext, string $today): ?array
    {
        if ($manual !== null && $manual >= $today) {
            return [
                'date' => $manual, 'source' => self::TARGET_MANUAL, 'type' => '', 'name' => '', 'url' => '',
                'users_id' => 0,
            ];
        }
        $upcoming = array_values(array_filter($planned, static fn (array $p): bool => $p['date'] >= $today));
        usort($upcoming, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        if ($upcoming !== []) {
            return $upcoming[0];
        }

        return $cycleNext !== null
            ? [
                'date' => $cycleNext['date'], 'source' => self::TARGET_CYCLE, 'type' => $cycleNext['type'],
                'name' => '', 'url' => '', 'users_id' => 0,
            ]
            : null;
    }

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
        'competence'        => ['phase' => 'do', 'clauses' => '7.2', 'requirements' => ['7.2']],
        'awareness'         => ['phase' => 'do', 'clauses' => '7.3', 'requirements' => ['7.3']],
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
     * Issue #774 (lot 2) : compétences. Des fonctions du SMSI, chacune avec ses compétences exigées
     * et ses titulaires, chaque écart avec une formation planifiée, et un dossier archivé comme
     * preuve. Les titulaires et les écarts valent null sans la lecture des utilisateurs : ils ne
     * comptent pas.
     */
    public static function competence(
        int $functions,
        int $withoutRequirements,
        ?int $withoutHolders,
        ?int $gapsWithoutAction,
        int $archives
    ): string {
        if ($functions === 0) {
            return self::STATUS_TODO;
        }

        return $withoutRequirements + (int) $withoutHolders + (int) $gapsWithoutAction > 0 || $archives === 0
            ? self::STATUS_PARTIAL
            : self::STATUS_DONE;
    }

    /**
     * Sensibilisation : une formation réalisée dans la période, et assez de participants l'ayant
     * suivie (null : aucun participant inscrit).
     */
    public static function awareness(
        int $trainings,
        int $deliveredRecently,
        ?int $completionRate,
        int $target = self::TRAINING_RATE_TARGET
    ): string {
        if ($trainings === 0) {
            return self::STATUS_TODO;
        }
        if ($deliveredRecently === 0) {
            return self::STATUS_PARTIAL;
        }

        return $completionRate === null || $completionRate >= $target
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
    public static function internalAudit(
        bool $recentCompleted,
        int $audits,
        int $coveragePercent,
        int $target = self::COVERAGE_TARGET
    ): string {
        if ($recentCompleted && $coveragePercent >= $target) {
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

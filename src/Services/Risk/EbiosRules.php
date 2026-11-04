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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #376 : règles d'une étude EBIOS Risk Manager (méthode de l'ANSSI, sous licence ouverte).
 * Échelles sur 4 niveaux, pertinence d'un couple source de risque / objectif visé, niveau de menace
 * d'une partie prenante, correspondance avec les échelles du registre des risques. Les seuils sont
 * indicatifs : l'étude les retient ou non. Sans base : testable seul.
 */
final class EbiosRules
{
    /** Les cinq ateliers, dans l'ordre. */
    public const WORKSHOPS = [1, 2, 3, 4, 5];

    public const LEVELS = [1, 2, 3, 4];

    /** Ordre des niveaux de risque, du plus faible au plus fort. */
    private const LEVEL_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** Niveau de menace à partir duquel une partie prenante entre en zone de contrôle, puis de danger. */
    public const CONTROL_THRESHOLD = 0.9;
    public const DANGER_THRESHOLD = 2.5;

    /** Gravité G1 à G4 => impact du registre des risques. */
    private const SEVERITY_TO_IMPACT = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];

    /** Vraisemblance V1 à V4 => probabilité du registre des risques. */
    private const LIKELIHOOD_TO_PROBABILITY = [1 => 'rare', 2 => 'possible', 3 => 'probable', 4 => 'certain'];

    /**
     * Ramène une saisie à un niveau de 1 à 4.
     */
    public static function level(mixed $value): int
    {
        return max(1, min(4, (int) $value));
    }

    /**
     * Pertinence d'un couple source de risque / objectif visé, d'après sa motivation, ses ressources
     * et son activité : low, medium ou high.
     */
    public static function sourceRelevance(int $motivation, int $resources, int $activity): string
    {
        $score = self::level($motivation) + self::level($resources) + self::level($activity);

        return $score >= 10 ? 'high' : ($score >= 7 ? 'medium' : 'low');
    }

    /**
     * Niveau de menace d'une partie prenante : (dépendance x pénétration) / (maturité x confiance).
     */
    public static function threatLevel(int $dependence, int $penetration, int $maturity, int $trust): float
    {
        $exposure = self::level($dependence) * self::level($penetration);
        $reliability = self::level($maturity) * self::level($trust);

        return round($exposure / $reliability, 2);
    }

    /**
     * Zone de la cartographie de la menace : watch (veille), control (contrôle) ou danger.
     */
    public static function threatZone(float $threat): string
    {
        if ($threat >= self::DANGER_THRESHOLD) {
            return 'danger';
        }

        return $threat >= self::CONTROL_THRESHOLD ? 'control' : 'watch';
    }

    public static function severityToImpact(int $severity): string
    {
        return self::SEVERITY_TO_IMPACT[self::level($severity)];
    }

    public static function likelihoodToProbability(int $likelihood): string
    {
        return self::LIKELIHOOD_TO_PROBABILITY[self::level($likelihood)];
    }

    /**
     * Niveau de risque d'un scénario opérationnel : celui que le registre des risques donnera à la même
     * gravité et à la même vraisemblance, d'après sa matrice (une seule source de vérité).
     *
     * @param array<string, array<string, string>> $riskMatrix Matrice du registre.
     */
    public static function riskLevel(
        int $severity,
        int $likelihood,
        array $riskMatrix = RiskMatrixDefaults::MATRIX
    ): string {
        return (new RiskScoringService($riskMatrix))->level(
            self::likelihoodToProbability($likelihood),
            self::severityToImpact($severity)
        );
    }

    /**
     * Grille gravité x vraisemblance : de G4 à G1, de V1 à V4, le niveau et le nombre de scénarios par case.
     *
     * @param list<array{severity: int, likelihood: int}> $scenarios
     * @param array<string, array<string, string>> $riskMatrix Matrice du registre.
     * @return array<int, array<int, array{level: string, count: int}>>
     */
    public static function riskMatrix(array $scenarios, array $riskMatrix = RiskMatrixDefaults::MATRIX): array
    {
        $grid = [];
        foreach (array_reverse(self::LEVELS) as $severity) {
            foreach (self::LEVELS as $likelihood) {
                $grid[$severity][$likelihood] = [
                    'level' => self::riskLevel($severity, $likelihood, $riskMatrix),
                    'count' => 0,
                ];
            }
        }
        foreach ($scenarios as $scenario) {
            $grid[self::level($scenario['severity'])][self::level($scenario['likelihood'])]['count']++;
        }

        return $grid;
    }

    /**
     * Trie des scénarios du risque le plus fort au plus faible, puis par gravité décroissante.
     *
     * @param list<array<string, mixed>> $scenarios Avec les clés level et severity.
     * @return list<array<string, mixed>>
     */
    public static function sortByRisk(array $scenarios): array
    {
        usort($scenarios, static fn(array $a, array $b): int => [
            self::LEVEL_RANK[$b['level']] ?? 0, (int) $b['severity'],
        ] <=> [
            self::LEVEL_RANK[$a['level']] ?? 0, (int) $a['severity'],
        ]);

        return $scenarios;
    }

    /**
     * Avancement de l'étude : un atelier est commencé dès qu'il contient un élément.
     *
     * @param array<int, int> $counts Atelier => nombre d'éléments.
     * @return array{started: int, total: int, next: ?int}
     */
    public static function progress(array $counts): array
    {
        $started = 0;
        $next = null;
        foreach (self::WORKSHOPS as $workshop) {
            if (($counts[$workshop] ?? 0) > 0) {
                $started++;
            } elseif ($next === null) {
                $next = $workshop;
            }
        }

        return ['started' => $started, 'total' => count(self::WORKSHOPS), 'next' => $next];
    }
}

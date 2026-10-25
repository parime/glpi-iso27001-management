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

namespace GlpiPlugin\Grcmanager\Services\Maturity;

/**
 * Issue #166 : score de maturité cyber, de 0 à 100, calculé à partir des données du plugin et
 * entièrement explicable (chaque composante, sa valeur, son poids, les pénalités). Aucun texte
 * de la norme : seulement des taux calculés sur ce que l'utilisateur a saisi.
 */
final class MaturityScore
{
    /** Poids des composantes (total 100). */
    public const WEIGHTS = [
        'soa'      => 40,
        'isms'     => 25,
        'evidence' => 15,
        'audit'    => 10,
        'training' => 10,
    ];

    /** Pénalité par élément en retard ou non résolu, plafonnée. */
    private const PENALTY_EACH = 2;

    private const PENALTY_MAX = 10;

    /** Bornes basses des niveaux 1 à 5. */
    private const LEVELS = [5 => 80, 4 => 60, 3 => 40, 2 => 20, 1 => 0];

    /**
     * Taux de mise en œuvre d'un ensemble de mesures applicables : « mis en œuvre » ou
     * « vérifié » comptent pour 1, « en cours » pour moitié. Sans mesure applicable : null
     * (rien à mesurer, la composante est écartée du calcul).
     *
     * @param array<string, int> $byStatus nombre de mesures applicables par état
     */
    public static function implementationRate(array $byStatus): ?float
    {
        $total = array_sum($byStatus);
        if ($total === 0) {
            return null;
        }
        $done = ($byStatus['implemented'] ?? 0) + ($byStatus['verified'] ?? 0)
            + 0.5 * ($byStatus['in_progress'] ?? 0);

        return round(100 * $done / $total, 1);
    }

    /**
     * Taux simple (part réalisée sur un total) ; null sans dénominateur.
     */
    public static function rate(int $done, int $total): ?float
    {
        return $total > 0 ? round(100 * min($done, $total) / $total, 1) : null;
    }

    /**
     * Score global. Une composante sans donnée (null) est écartée et son poids réparti sur les
     * autres, pour qu'un module non utilisé ne fasse pas chuter le score.
     *
     * @param array<string, ?float> $components valeurs de 0 à 100 par clé de WEIGHTS
     * @param array{capa_overdue: int, incidents_open: int} $penalties
     * @return array{score: int, level: int, base: float, penalty: int,
     *     components: array<string, array{value: ?float, weight: int, effective_weight: float}>}
     */
    public static function compute(array $components, array $penalties): array
    {
        $usedWeight = 0;
        foreach (self::WEIGHTS as $key => $weight) {
            if (($components[$key] ?? null) !== null) {
                $usedWeight += $weight;
            }
        }
        $base = 0.0;
        $details = [];
        foreach (self::WEIGHTS as $key => $weight) {
            $value = $components[$key] ?? null;
            $effective = $value !== null && $usedWeight > 0 ? 100 * $weight / $usedWeight : 0.0;
            $base += $value !== null ? $value * $effective / 100 : 0.0;
            $details[$key] = ['value' => $value, 'weight' => $weight, 'effective_weight' => round($effective, 1)];
        }
        $penalty = min(self::PENALTY_MAX, self::PENALTY_EACH * max(0, $penalties['capa_overdue']))
            + min(self::PENALTY_MAX, self::PENALTY_EACH * max(0, $penalties['incidents_open']));
        $score = (int) max(0, min(100, round($base) - $penalty));

        return [
            'score'      => $score,
            'level'      => self::level($score),
            'base'       => round($base, 1),
            'penalty'    => $penalty,
            'components' => $details,
        ];
    }

    public static function level(int $score): int
    {
        foreach (self::LEVELS as $level => $floor) {
            if ($score >= $floor) {
                return $level;
            }
        }

        return 1;
    }
}

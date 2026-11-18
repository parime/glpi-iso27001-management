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
 * Issue #706 : taille de la matrice des risques (4x4 par défaut, 5x5 au choix), sans dépendance à
 * GLPI pour rester testable unitairement.
 *
 * Clés stables : la matrice 5x5 reprend les quatre clés de chaque axe de la 4x4 et en ajoute une
 * en bas d'échelle (`very_rare` pour la probabilité, `negligible` pour l'impact). Une valeur
 * enregistrée en 4x4 reste donc une valeur valide en 5x5, sans réécriture. Le niveau de risque
 * calculé garde ses quatre valeurs (low, medium, high, critical) quelle que soit la taille :
 * l'appétence, les indicateurs, les cohérences et les notifications n'en dépendent pas.
 */
final class RiskScale
{
    public const DEFAULT_SIZE = 4;

    /** @var list<int> */
    public const SIZES = [4, 5];

    /**
     * Clés de probabilité, de la plus faible à la plus forte (le poids est le rang, à partir de 1).
     *
     * @var array<int, list<string>>
     */
    public const PROBABILITIES = [
        4 => ['rare', 'possible', 'probable', 'certain'],
        5 => ['very_rare', 'rare', 'possible', 'probable', 'certain'],
    ];

    /**
     * Clés d'impact, de la plus faible à la plus forte.
     *
     * @var array<int, list<string>>
     */
    public const IMPACTS = [
        4 => ['low', 'medium', 'high', 'critical'],
        5 => ['negligible', 'low', 'medium', 'high', 'critical'],
    ];

    /**
     * Niveaux de risque calculés, identiques pour toutes les tailles.
     *
     * @var list<string>
     */
    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * Seuils de score (probabilité x impact) des niveaux medium, high et critical, utilisés
     * seulement quand la grille ne couvre pas une combinaison (grille incomplète ou abîmée).
     *
     * @var array<int, array{medium: int, high: int, critical: int}>
     */
    private const SCORE_THRESHOLDS = [
        4 => ['medium' => 4, 'high' => 8, 'critical' => 12],
        5 => ['medium' => 5, 'high' => 10, 'critical' => 16],
    ];

    public static function normalizeSize(int $size): int
    {
        return in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
    }

    /**
     * @return list<string>
     */
    public static function probabilityKeys(int $size): array
    {
        return self::PROBABILITIES[self::normalizeSize($size)];
    }

    /**
     * @return list<string>
     */
    public static function impactKeys(int $size): array
    {
        return self::IMPACTS[self::normalizeSize($size)];
    }

    /**
     * Rang d'une valeur sur son axe (1 pour la plus faible), 0 si elle n'appartient pas à l'échelle.
     */
    public static function weight(string $axis, string $key, int $size): int
    {
        $keys = $axis === 'probability' ? self::probabilityKeys($size) : self::impactKeys($size);
        $index = array_search($key, $keys, true);

        return $index === false ? 0 : $index + 1;
    }

    public static function levelFromScore(float $score, int $size): string
    {
        $thresholds = self::SCORE_THRESHOLDS[self::normalizeSize($size)];

        return match (true) {
            $score >= $thresholds['critical'] => 'critical',
            $score >= $thresholds['high']     => 'high',
            $score >= $thresholds['medium']   => 'medium',
            default                           => 'low',
        };
    }

    /**
     * Grille complète pour une taille : la grille par défaut de cette taille, recouverte des valeurs
     * enregistrées qui sont valides (clés de l'échelle, niveau connu). Une grille enregistrée pour une
     * autre taille, incomplète ou abîmée ne laisse jamais une case sans niveau.
     *
     * @param array<mixed> $stored
     * @return array<string, array<string, string>>
     */
    public static function normalizeMatrix(array $stored, int $size): array
    {
        $matrix = RiskMatrixDefaults::forSize(self::normalizeSize($size));
        foreach ($matrix as $probability => $impacts) {
            foreach (array_keys($impacts) as $impact) {
                $value = $stored[$probability][$impact] ?? null;
                if (is_string($value) && in_array($value, self::LEVELS, true)) {
                    $matrix[$probability][$impact] = $value;
                }
            }
        }

        return $matrix;
    }

    /**
     * Correspondance proposée entre les valeurs d'une taille et celles d'une autre. Parmi les
     * correspondances qui respectent l'ordre de chaque axe (une valeur plus forte ne va jamais vers
     * une valeur plus faible ; en réduisant la taille, chaque nouvelle valeur reçoit au moins une
     * ancienne), celle retenue change le niveau du moins de cases possible entre la grille actuelle
     * et la grille par défaut de la nouvelle taille ; à égalité, celle qui garde le plus de clés.
     * L'administrateur la vérifie et peut la modifier avant de l'appliquer.
     *
     * @param array<mixed>|null $currentMatrix grille actuelle (grille par défaut de $from si null)
     * @return array{probability: array<string, string>, impact: array<string, string>}
     */
    public static function proposedMapping(int $from, int $to, ?array $currentMatrix = null): array
    {
        $from = self::normalizeSize($from);
        $to = self::normalizeSize($to);
        $current = self::normalizeMatrix($currentMatrix ?? RiskMatrixDefaults::forSize($from), $from);
        $target = RiskMatrixDefaults::forSize($to);
        $sourceP = self::probabilityKeys($from);
        $sourceI = self::impactKeys($from);
        $targetP = self::probabilityKeys($to);
        $targetI = self::impactKeys($to);

        $best = null;
        $bestScore = null;
        foreach (self::orderedMaps(count($sourceP), count($targetP)) as $mapP) {
            foreach (self::orderedMaps(count($sourceI), count($targetI)) as $mapI) {
                $changed = 0;
                foreach ($sourceP as $p => $probability) {
                    $targetRow = $target[$targetP[$mapP[$p]]];
                    foreach ($sourceI as $i => $impact) {
                        $changed += (int) ($current[$probability][$impact] !== $targetRow[$targetI[$mapI[$i]]]);
                    }
                }
                $renamed = 0;
                foreach ($sourceP as $p => $probability) {
                    $renamed += (int) ($probability !== $targetP[$mapP[$p]]);
                }
                foreach ($sourceI as $i => $impact) {
                    $renamed += (int) ($impact !== $targetI[$mapI[$i]]);
                }
                $score = [$changed, $renamed];
                if ($bestScore === null || $score < $bestScore) {
                    $bestScore = $score;
                    $best = [$mapP, $mapI];
                }
            }
        }

        $mapping = ['probability' => [], 'impact' => []];
        foreach ($sourceP as $p => $probability) {
            $mapping['probability'][$probability] = $targetP[$best[0][$p] ?? $p] ?? $probability;
        }
        foreach ($sourceI as $i => $impact) {
            $mapping['impact'][$impact] = $targetI[$best[1][$i] ?? $i] ?? $impact;
        }

        return $mapping;
    }

    /**
     * Correspondances d'une échelle de $n valeurs vers une échelle de $m valeurs qui respectent
     * l'ordre : strictement croissantes si $n <= $m, croissantes et couvrant toute l'échelle cible
     * (pas de plus d'un rang) si $n > $m.
     *
     * @return list<list<int>>
     */
    private static function orderedMaps(int $n, int $m): array
    {
        $maps = [[]];
        for ($index = 0; $index < $n; $index++) {
            $next = [];
            foreach ($maps as $map) {
                $previous = $index === 0 ? -1 : $map[$index - 1];
                for ($value = max(0, $previous); $value < $m; $value++) {
                    if ($n <= $m && $value === $previous) {
                        continue;
                    }
                    if ($n > $m && ($value - $previous > 1 || ($index === 0 && $value !== 0))) {
                        continue;
                    }
                    $next[] = array_merge($map, [$value]);
                }
            }
            $maps = $next;
        }

        return array_values(array_filter(
            $maps,
            static fn (array $map): bool => $n <= $m || end($map) === $m - 1
        ));
    }

    /**
     * Correspondance choisie par l'administrateur, ramenée à une énumération fermée : toute ancienne
     * valeur a une nouvelle valeur de l'échelle cible ; une saisie absente ou inconnue reprend la
     * proposition.
     *
     * @param array<mixed> $posted ['probability' => [ancienne => nouvelle], 'impact' => [...]]
     * @param array<mixed>|null $currentMatrix grille actuelle, pour la proposition
     * @return array{probability: array<string, string>, impact: array<string, string>}
     */
    public static function sanitizeMapping(array $posted, int $from, int $to, ?array $currentMatrix = null): array
    {
        $mapping = self::proposedMapping($from, $to, $currentMatrix);
        foreach (['probability' => self::probabilityKeys($to), 'impact' => self::impactKeys($to)] as $axis => $target) {
            foreach (array_keys($mapping[$axis]) as $key) {
                $value = $posted[$axis][$key] ?? null;
                if (is_string($value) && in_array($value, $target, true)) {
                    $mapping[$axis][$key] = $value;
                }
            }
        }

        return $mapping;
    }
}

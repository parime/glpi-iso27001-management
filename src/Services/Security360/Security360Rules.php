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

namespace GlpiPlugin\Grcmanager\Services\Security360;

/**
 * Issue #837 : règles pures de la vue « Sécurité 360° » d'un actif (sans GLPI, testées seules).
 *
 * Score global : moyenne, pondérée par le poids de chaque source, des points de chaque source évaluée
 * (conforme 100, à surveiller 50, critique 0) ; une source informative ou absente ne compte pas.
 * Niveau : bon à partir de 80, moyen à partir de 50, faible en dessous ; un seul point critique
 * empêche le niveau « bon », pour qu'une moyenne flatteuse ne cache pas un écart grave.
 */
final class Security360Rules
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_INFO = 'info';

    public const STATUSES = [self::STATUS_OK, self::STATUS_WARNING, self::STATUS_CRITICAL, self::STATUS_INFO];

    public const LEVEL_GOOD = 'good';
    public const LEVEL_FAIR = 'fair';
    public const LEVEL_POOR = 'poor';
    public const LEVEL_NONE = 'none';

    public const GOOD_FROM = 80;
    public const FAIR_FROM = 50;

    private const POINTS = [self::STATUS_OK => 100, self::STATUS_WARNING => 50, self::STATUS_CRITICAL => 0];

    /**
     * Points d'un état, null s'il ne compte pas dans le score.
     */
    public static function points(string $status): ?int
    {
        return self::POINTS[$status] ?? null;
    }

    /**
     * Normalise le résultat d'une source : null si absent ou mal formé, état inconnu ramené à « info ».
     *
     * @return array{status: string, summary: string, url: string, facts: list<string>}|null
     */
    public static function normalize(mixed $result): ?array
    {
        if (!is_array($result) || trim((string) ($result['summary'] ?? '')) === '') {
            return null;
        }
        $status = (string) ($result['status'] ?? '');

        return [
            'status'  => in_array($status, self::STATUSES, true) ? $status : self::STATUS_INFO,
            'summary' => (string) $result['summary'],
            'url'     => (string) ($result['url'] ?? ''),
            'facts'   => array_values(array_map('strval', (array) ($result['facts'] ?? []))),
        ];
    }

    /**
     * Score et niveau d'après les sources présentes.
     *
     * @param list<array{status: string, weight: int}> $sources
     * @return array{score: ?int, level: string, evaluated: int, critical: int, warning: int}
     */
    public static function score(array $sources): array
    {
        $total = 0;
        $weights = 0;
        $evaluated = 0;
        $critical = 0;
        $warning = 0;
        foreach ($sources as $source) {
            $points = self::points($source['status']);
            $weight = max(0, (int) $source['weight']);
            if ($points === null || $weight === 0) {
                continue;
            }
            $evaluated++;
            $critical += $source['status'] === self::STATUS_CRITICAL ? 1 : 0;
            $warning += $source['status'] === self::STATUS_WARNING ? 1 : 0;
            $total += $points * $weight;
            $weights += $weight;
        }
        if ($weights === 0) {
            return ['score' => null, 'level' => self::LEVEL_NONE, 'evaluated' => 0, 'critical' => 0, 'warning' => 0];
        }
        $score = (int) round($total / $weights);

        return [
            'score'     => $score,
            'level'     => self::level($score, $critical > 0),
            'evaluated' => $evaluated,
            'critical'  => $critical,
            'warning'   => $warning,
        ];
    }

    public static function level(int $score, bool $hasCritical): string
    {
        if ($score >= self::GOOD_FROM && !$hasCritical) {
            return self::LEVEL_GOOD;
        }

        return $score >= self::FAIR_FROM ? self::LEVEL_FAIR : self::LEVEL_POOR;
    }

    /**
     * Ordre d'affichage : critiques, à surveiller, conformes puis informatives, en gardant l'ordre
     * du registre à état égal.
     *
     * @template T of array{status: string}
     * @param list<T> $sources
     * @return list<T>
     */
    public static function sort(array $sources): array
    {
        $rank = [self::STATUS_CRITICAL => 0, self::STATUS_WARNING => 1, self::STATUS_OK => 2, self::STATUS_INFO => 3];
        $indexed = [];
        foreach ($sources as $i => $source) {
            $indexed[] = [$rank[$source['status']] ?? 4, $i, $source];
        }
        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): array => $row[2], $indexed);
    }
}

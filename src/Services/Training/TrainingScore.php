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

namespace GlpiPlugin\Grcmanager\Services\Training;

/**
 * Issue #139 : note d'un participant (ex. 32 / 40), pourcentage et résultat calculé depuis le
 * seuil de réussite de la formation, taux de réussite d'une session.
 */
final class TrainingScore
{
    /**
     * Une formation est notée quand elle a une note maximale et un seuil de réussite.
     */
    public static function isScored(int $maxScore, int $threshold): bool
    {
        return $maxScore > 0 && $threshold > 0;
    }

    /**
     * Note saisie (virgule acceptée), ou null si vide, négative ou supérieure au maximum.
     */
    public static function parse(string $input, int $maxScore): ?float
    {
        $input = str_replace(',', '.', trim($input));
        if ($input === '' || !is_numeric($input)) {
            return null;
        }
        $score = (float) $input;

        return $score >= 0 && $score <= $maxScore ? $score : null;
    }

    public static function percent(float $score, int $maxScore): int
    {
        return $maxScore > 0 ? (int) round($score / $maxScore * 100) : 0;
    }

    /**
     * 'passed' ou 'failed' depuis la note, null si la formation n'est pas notée ou sans note.
     */
    public static function result(?float $score, int $maxScore, int $threshold): ?string
    {
        if ($score === null || !self::isScored($maxScore, $threshold)) {
            return null;
        }

        return $score / $maxScore * 100 >= $threshold ? 'passed' : 'failed';
    }

    /**
     * Taux de réussite parmi les participants évalués (réussie ou échouée).
     *
     * @param list<string> $results résultats des participants
     * @return array{passed: int, assessed: int, rate: ?int}
     */
    public static function sessionRate(array $results): array
    {
        $assessed = count(array_filter(
            $results,
            static fn (string $r): bool => in_array($r, ['passed', 'failed'], true)
        ));
        $passed = count(array_filter($results, static fn (string $r): bool => $r === 'passed'));

        return [
            'passed'   => $passed,
            'assessed' => $assessed,
            'rate'     => $assessed > 0 ? (int) round($passed / $assessed * 100) : null,
        ];
    }

    /**
     * Affichage « 32/40 (80 %) » : décimales seulement si nécessaire.
     */
    public static function format(float $score, int $maxScore): string
    {
        return sprintf('%s/%d (%d %%)', self::formatValue($score), $maxScore, self::percent($score, $maxScore));
    }

    /**
     * « 32 », « 32,5 » : décimales seulement si nécessaire.
     */
    public static function formatValue(float $score): string
    {
        $value = number_format($score, 2, ',', '');

        return str_contains($value, ',') ? rtrim(rtrim($value, '0'), ',') : $value;
    }
}

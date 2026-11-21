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
 * Issue #603 : règles de la maturité par mesure (niveau actuel, niveau cible, écart), sans accès à la
 * base. Échelle de 0 (inexistant) à 5 (optimisé) ; un niveau absent (null) veut dire « non évalué ».
 *
 * Ces niveaux sont facultatifs et indépendants : ils ne changent ni l'état de mise en œuvre des
 * mesures de la SoA ni le score de maturité global (MaturityScore).
 */
final class MaturityGapRules
{
    public const MIN_LEVEL = 0;
    public const MAX_LEVEL = 5;

    /** Longueur maximale d'un libellé de niveau personnalisé. */
    public const LABEL_MAX_LENGTH = 64;

    public const MAX_NAME_LENGTH = 255;

    /** Écart à partir duquel l'action proposée est de gravité majeure. */
    public const MAJOR_GAP = 3;

    /** @return list<int> */
    public static function levels(): array
    {
        return range(self::MIN_LEVEL, self::MAX_LEVEL);
    }

    /**
     * Niveau saisi (formulaire, base) : un entier de 0 à 5, ou null (non évalué, valeur vide ou hors
     * de l'échelle).
     */
    public static function normalizeLevel(mixed $value): ?int
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^\d+$/', $text)) {
            return null;
        }
        $level = (int) $text;

        return $level >= self::MIN_LEVEL && $level <= self::MAX_LEVEL ? $level : null;
    }

    /**
     * Écart restant à combler (cible moins actuel), 0 si la cible est atteinte ou dépassée, null si
     * l'un des deux niveaux manque.
     */
    public static function gap(?int $current, ?int $target): ?int
    {
        if ($current === null || $target === null) {
            return null;
        }

        return max(0, $target - $current);
    }

    /** Date AAAA-MM-JJ valide, ou null. */
    public static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    public static function cleanText(mixed $value, int $maxLength = 65535): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(str_replace("\0", '', (string) $value)), 0, $maxLength);
    }

    /**
     * Libellés personnalisés gardés : niveaux connus seulement, texte nettoyé, vides retirés (le
     * libellé par défaut, traduit, s'applique alors).
     *
     * @param array<mixed> $labels
     * @return array<int, string>
     */
    public static function sanitizeLabels(array $labels): array
    {
        $clean = [];
        foreach (self::levels() as $level) {
            $value = $labels[$level] ?? ($labels[(string) $level] ?? null);
            $text = self::cleanText($value, self::LABEL_MAX_LENGTH);
            if ($text !== '') {
                $clean[$level] = $text;
            }
        }

        return $clean;
    }

    /**
     * Libellés affichés : le libellé personnalisé s'il existe, sinon le libellé par défaut.
     *
     * @param array<int, string> $defaults
     * @param array<int, string> $custom
     * @return array<int, string>
     */
    public static function mergeLabels(array $defaults, array $custom): array
    {
        $labels = [];
        foreach (self::levels() as $level) {
            $labels[$level] = $custom[$level] ?? ($defaults[$level] ?? (string) $level);
        }

        return $labels;
    }

    /**
     * Classes de badge d'un niveau (rouge pour 0 et 1, jusqu'au vert pour 5), gris si non évalué.
     */
    public static function levelBadgeClass(?int $level): string
    {
        return match ($level) {
            0, 1    => 'bg-red-subtle text-red-emphasis',
            2       => 'bg-orange-subtle text-orange-emphasis',
            3       => 'bg-yellow-subtle text-yellow-emphasis',
            4       => 'bg-lime-subtle text-lime-emphasis',
            5       => 'bg-green-subtle text-green-emphasis',
            default => 'bg-secondary-subtle text-secondary-emphasis',
        };
    }

    /** Classes de badge d'un écart : vert sans écart, puis jaune, orange et rouge. */
    public static function gapBadgeClass(?int $gap): string
    {
        return match (true) {
            $gap === null          => 'bg-secondary-subtle text-secondary-emphasis',
            $gap === 0             => 'bg-green-subtle text-green-emphasis',
            $gap === 1             => 'bg-yellow-subtle text-yellow-emphasis',
            $gap < self::MAJOR_GAP => 'bg-orange-subtle text-orange-emphasis',
            default                => 'bg-red-subtle text-red-emphasis',
        };
    }

    /**
     * Écarts à traiter (écart supérieur à 0), du plus grand au plus petit, puis par échéance (les
     * lignes sans échéance en dernier) et par code de mesure.
     *
     * @param list<array<string, mixed>> $rows lignes avec code, gap et target_date
     * @return list<array<string, mixed>>
     */
    public static function sortGaps(array $rows): array
    {
        $gaps = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['gap'] ?? 0) > 0));
        usort($gaps, static function (array $a, array $b): int {
            $byGap = (int) $b['gap'] <=> (int) $a['gap'];
            if ($byGap !== 0) {
                return $byGap;
            }
            $da = (string) ($a['target_date'] ?? '');
            $db = (string) ($b['target_date'] ?? '');
            if ($da !== $db) {
                if ($da === '') {
                    return 1;
                }
                if ($db === '') {
                    return -1;
                }

                return strcmp($da, $db);
            }

            return strnatcmp((string) $a['code'], (string) $b['code']);
        });

        return $gaps;
    }

    /**
     * Synthèse d'une campagne : mesures, mesures évaluées (niveau actuel), cibles fixées, écarts,
     * moyennes des niveaux actuel et cible (sur les lignes qui ont les deux niveaux).
     *
     * @param list<array{current: ?int, target: ?int}> $rows
     * @return array{total: int, assessed: int, targeted: int, gaps: int, gap_points: int,
     *     average_current: ?float, average_target: ?float}
     */
    public static function summary(array $rows): array
    {
        $assessed = 0;
        $targeted = 0;
        $gaps = 0;
        $gapPoints = 0;
        $current = [];
        $target = [];
        foreach ($rows as $row) {
            $c = $row['current'];
            $t = $row['target'];
            if ($c !== null) {
                $assessed++;
            }
            if ($t !== null) {
                $targeted++;
            }
            $gap = self::gap($c, $t);
            if ($gap !== null) {
                $current[] = $c;
                $target[] = $t;
                if ($gap > 0) {
                    $gaps++;
                    $gapPoints += $gap;
                }
            }
        }

        return [
            'total'           => count($rows),
            'assessed'        => $assessed,
            'targeted'        => $targeted,
            'gaps'            => $gaps,
            'gap_points'      => $gapPoints,
            'average_current' => self::average($current),
            'average_target'  => self::average($target),
        ];
    }

    /**
     * Niveaux moyens actuel et cible par thème, sur les lignes qui ont les deux niveaux (radar).
     *
     * @param list<array{theme: string, current: ?int, target: ?int}> $rows
     * @param list<string> $themes ordre d'affichage
     * @return array<string, array{current: float, target: float, count: int}>
     */
    public static function themeAverages(array $rows, array $themes): array
    {
        $byTheme = [];
        foreach ($rows as $row) {
            if ($row['current'] === null || $row['target'] === null) {
                continue;
            }
            $byTheme[$row['theme']]['current'][] = $row['current'];
            $byTheme[$row['theme']]['target'][] = $row['target'];
        }
        $averages = [];
        foreach ($themes as $theme) {
            if (!isset($byTheme[$theme])) {
                continue;
            }
            $averages[$theme] = [
                'current' => (float) self::average($byTheme[$theme]['current']),
                'target'  => (float) self::average($byTheme[$theme]['target']),
                'count'   => count($byTheme[$theme]['current']),
            ];
        }

        return $averages;
    }

    /**
     * Comparaison de deux campagnes, mesure par mesure (niveaux actuels) : progression, recul ou
     * stabilité ; une mesure évaluée dans une seule des deux campagnes n'est pas comparée.
     *
     * @param array<string, ?int> $before code => niveau de la campagne précédente
     * @param array<string, ?int> $after code => niveau de la campagne affichée
     * @return array{lines: array<string, array{before: ?int, after: ?int, delta: ?int}>,
     *     improved: int, declined: int, unchanged: int, compared: int}
     */
    public static function compare(array $before, array $after): array
    {
        $codes = array_unique(array_merge(array_keys($before), array_keys($after)));
        usort($codes, 'strnatcmp');
        $lines = [];
        $counts = ['improved' => 0, 'declined' => 0, 'unchanged' => 0];
        foreach ($codes as $code) {
            $b = $before[$code] ?? null;
            $a = $after[$code] ?? null;
            $delta = ($a === null || $b === null) ? null : $a - $b;
            if ($delta !== null) {
                $counts[$delta > 0 ? 'improved' : ($delta < 0 ? 'declined' : 'unchanged')]++;
            }
            $lines[(string) $code] = ['before' => $b, 'after' => $a, 'delta' => $delta];
        }

        return ['lines' => $lines] + $counts + ['compared' => array_sum($counts)];
    }

    /**
     * Valeurs proposées pour l'action ouverte depuis un écart : une piste d'amélioration (le CAPA
     * reste facultatif), mineure, ou majeure à partir d'un écart de 3 niveaux. Null sans écart.
     *
     * @return array{finding_type: string, severity: string}|null
     */
    public static function findingPreset(?int $gap): ?array
    {
        if ($gap === null || $gap <= 0) {
            return null;
        }

        return [
            'finding_type' => 'improvement',
            'severity'     => $gap >= self::MAJOR_GAP ? 'major' : 'minor',
        ];
    }

    /**
     * @param list<int> $values
     */
    private static function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }
}

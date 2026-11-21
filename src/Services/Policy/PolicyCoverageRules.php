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

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Issue #879 : couverture des mesures de la SoA par les textes de la bibliothèque des politiques
 * (clause 5.2, mesure A.5.1). Règles pures, sans base de données :
 *
 * - état d'un texte relié : approuvé, approuvé mais à réviser (date de revue dépassée), non approuvé
 *   (brouillon ou archivé) ;
 * - état d'une mesure : couverte (au moins un texte approuvé à jour), à réviser, non approuvée,
 *   non couverte ;
 * - lecture d'une liste de codes saisie à la main pour relier plusieurs mesures d'un coup
 *   (« A.5.1, A.5.15-A.5.18, A.8 »).
 */
final class PolicyCoverageRules
{
    public const TEXT_APPROVED     = 'approved';
    public const TEXT_REVIEW_DUE   = 'review_due';
    public const TEXT_NOT_APPROVED = 'not_approved';

    public const STATUS_COVERED      = 'covered';
    public const STATUS_REVIEW_DUE   = 'review_due';
    public const STATUS_NOT_APPROVED = 'not_approved';
    public const STATUS_UNCOVERED    = 'uncovered';

    /** Du plus grave au moins grave (ordre du rapport). */
    public const STATUSES = [
        self::STATUS_UNCOVERED,
        self::STATUS_NOT_APPROVED,
        self::STATUS_REVIEW_DUE,
        self::STATUS_COVERED,
    ];

    /** Filtre du rapport : toutes les mesures ou seulement les écarts. */
    public const FILTER_ALL  = 'all';
    public const FILTER_GAPS = 'gaps';

    /** Longueur maximale du libellé de section (colonne varchar). */
    public const SECTION_MAX = 255;

    /**
     * État d'un texte relié à une mesure.
     *
     * @param array<string, mixed> $policy ligne de la politique (status, next_review_date)
     */
    public static function textState(array $policy, string $today): string
    {
        if (($policy['status'] ?? '') !== PolicyLifecycle::STATUS_APPROVED) {
            return self::TEXT_NOT_APPROVED;
        }
        $review = (string) ($policy['next_review_date'] ?? '');
        if ($review !== '' && substr($review, 0, 10) < $today) {
            return self::TEXT_REVIEW_DUE;
        }

        return self::TEXT_APPROVED;
    }

    /**
     * État d'une mesure d'après les états de ses textes : le meilleur texte compte.
     *
     * @param list<string> $textStates
     */
    public static function controlStatus(array $textStates): string
    {
        if ($textStates === []) {
            return self::STATUS_UNCOVERED;
        }
        if (in_array(self::TEXT_APPROVED, $textStates, true)) {
            return self::STATUS_COVERED;
        }
        if (in_array(self::TEXT_REVIEW_DUE, $textStates, true)) {
            return self::STATUS_REVIEW_DUE;
        }

        return self::STATUS_NOT_APPROVED;
    }

    public static function isGap(string $status): bool
    {
        return $status !== self::STATUS_COVERED;
    }

    public static function normalizeFilter(mixed $value): string
    {
        return $value === self::FILTER_ALL ? self::FILTER_ALL : self::FILTER_GAPS;
    }

    /**
     * Compte des mesures par état, et taux de couverture (mesures couvertes sur mesures applicables).
     *
     * @param list<string> $statuses
     * @return array{total: int, counts: array<string, int>, rate: ?int}
     */
    public static function summarize(array $statuses): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($statuses as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = count($statuses);

        return [
            'total'  => $total,
            'counts' => $counts,
            'rate'   => $total > 0 ? (int) floor($counts[self::STATUS_COVERED] * 100 / $total) : null,
        ];
    }

    public static function normalizeSection(mixed $value): string
    {
        $section = trim(preg_replace('/\s+/u', ' ', is_string($value) ? $value : '') ?? '');

        return mb_substr($section, 0, self::SECTION_MAX);
    }

    /**
     * Codes saisis à la main, séparés par des virgules, points-virgules ou espaces : un code entier
     * (A.5.1), un thème (A.5 : toutes les mesures A.5.x) ou une plage d'un même thème
     * (A.5.15-A.5.18, ou A.5.15-18). Seuls les codes de $available sont retenus, dans leur ordre ;
     * les éléments non reconnus sont rendus à part pour être signalés.
     *
     * @param list<string> $available codes de la SoA (A.5.1...)
     * @return array{codes: list<string>, unknown: list<string>}
     */
    public static function parseCodes(string $input, array $available): array
    {
        $selected = [];
        $unknown = [];
        $tokens = preg_split('/[\s,;]+/u', strtoupper(trim($input))) ?: [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $token = str_starts_with($token, 'A') ? $token : 'A.' . ltrim($token, '.');
            $matched = self::matchToken($token, $available);
            if ($matched === []) {
                $unknown[] = $token;
                continue;
            }
            foreach ($matched as $code) {
                $selected[$code] = true;
            }
        }
        $codes = array_values(array_filter($available, static fn (string $c): bool => isset($selected[$c])));

        return ['codes' => $codes, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * @param list<string> $available
     * @return list<string>
     */
    private static function matchToken(string $token, array $available): array
    {
        if (preg_match('/^A\.(\d+)\.(\d+)-(?:A\.\1\.)?(\d+)$/', $token, $m) === 1) {
            [$theme, $from, $to] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            return array_values(array_filter($available, static function (string $code) use ($theme, $from, $to): bool {
                if (preg_match('/^A\.(\d+)\.(\d+)$/', $code, $c) !== 1) {
                    return false;
                }

                return (int) $c[1] === $theme && (int) $c[2] >= $from && (int) $c[2] <= $to;
            }));
        }
        if (preg_match('/^A\.\d+$/', $token) === 1) {
            return array_values(array_filter($available, static fn (string $c): bool => str_starts_with($c, $token . '.')));
        }

        return in_array($token, $available, true) ? [$token] : [];
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

/**
 * Issue #377 : règles de l'analyse d'impact sur l'activité (BIA, ISO 22301 8.2.2), sans base.
 *
 * Pour chaque processus métier, l'impact d'une interruption est noté par durée (4 h, 1 jour,
 * 3 jours, 1 semaine, 1 mois) et par nature (financier, juridique, image, opérationnel), de 0
 * (non évalué) à 4 (critique). On en déduit la durée à partir de laquelle l'interruption devient
 * inacceptable, qui borne la durée maximale d'interruption admissible (DMIA) ; le RTO doit rester
 * en deçà de la DMIA, et les actifs dont dépend le processus doivent reprendre au moins aussi vite.
 */
final class BusinessImpactRules
{
    /** Durées d'interruption évaluées, en heures. */
    public const HORIZONS = [4, 24, 72, 168, 720];

    /** Natures d'impact évaluées. */
    public const CATEGORIES = ['financial', 'legal', 'reputation', 'operational'];

    /** 0 : non évalué, 1 : négligeable, 2 : limité, 3 : grave, 4 : critique. */
    public const MAX_LEVEL = 4;

    /** Niveau à partir duquel l'impact est jugé inacceptable. */
    public const UNACCEPTABLE = 3;

    /** Durées proposées pour la DMIA, le RTO et le RPO d'un processus, en heures (0 : non définie). */
    public const DURATIONS = [1, 4, 8, 24, 48, 72, 168, 336, 720];

    public static function sanitizeLevel(mixed $value): int
    {
        $level = is_numeric($value) ? (int) $value : 0;

        return $level >= 0 && $level <= self::MAX_LEVEL ? $level : 0;
    }

    public static function sanitizeDuration(mixed $value): int
    {
        $hours = is_numeric($value) ? (int) $value : 0;

        return in_array($hours, self::DURATIONS, true) ? $hours : 0;
    }

    /**
     * Grille complète (toutes les durées, toutes les natures), à partir des lignes enregistrées.
     *
     * @param iterable<array<string, mixed>> $rows lignes avec horizon_hours et une colonne par nature
     * @return array<int, array<string, int>> durée => nature => niveau
     */
    public static function grid(iterable $rows): array
    {
        $grid = [];
        foreach (self::HORIZONS as $horizon) {
            $grid[$horizon] = array_fill_keys(self::CATEGORIES, 0);
        }
        foreach ($rows as $row) {
            $horizon = (int) ($row['horizon_hours'] ?? 0);
            if (!isset($grid[$horizon])) {
                continue;
            }
            foreach (self::CATEGORIES as $category) {
                $grid[$horizon][$category] = self::sanitizeLevel($row[$category] ?? 0);
            }
        }

        return $grid;
    }

    /**
     * @param array<int, array<string, int>> $grid
     */
    public static function isAssessed(array $grid): bool
    {
        foreach ($grid as $levels) {
            if (max($levels) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plus courte durée d'interruption dont l'impact atteint le seuil inacceptable (0 : jamais).
     *
     * @param array<int, array<string, int>> $grid
     */
    public static function firstUnacceptableHorizon(array $grid, int $threshold = self::UNACCEPTABLE): int
    {
        ksort($grid);
        foreach ($grid as $horizon => $levels) {
            if ($levels !== [] && max($levels) >= $threshold) {
                return (int) $horizon;
            }
        }

        return 0;
    }

    /**
     * Plus grand impact par durée, toutes natures confondues.
     *
     * @param array<int, array<string, int>> $grid
     * @return array<int, int>
     */
    public static function worstByHorizon(array $grid): array
    {
        $worst = [];
        foreach ($grid as $horizon => $levels) {
            $worst[$horizon] = $levels === [] ? 0 : (int) max($levels);
        }

        return $worst;
    }

    /**
     * Cohérence des objectifs du processus avec sa BIA. Codes renvoyés :
     * - bia_missing : aucun impact évalué ;
     * - mtpd_missing, rto_missing : objectif non défini ;
     * - rto_above_mtpd : le RTO dépasse la DMIA ;
     * - mtpd_above_impact : la DMIA dépasse la durée à partir de laquelle l'impact devient
     *   inacceptable selon la BIA ;
     * - rpo_above_mtpd : la perte de données admise dépasse la DMIA (incohérent : on ne peut pas
     *   reprendre en perdant plus que l'interruption tolérée) ;
     * - impact_decreasing : un impact baisse quand l'interruption dure plus longtemps.
     *
     * @param array<int, array<string, int>> $grid
     * @return list<string>
     */
    public static function objectiveIssues(int $mtpd, int $rto, int $rpo, array $grid): array
    {
        $issues = [];
        if (!self::isAssessed($grid)) {
            $issues[] = 'bia_missing';
        }
        if ($mtpd <= 0) {
            $issues[] = 'mtpd_missing';
        }
        if ($rto <= 0) {
            $issues[] = 'rto_missing';
        }
        if ($rto > 0 && $mtpd > 0 && $rto > $mtpd) {
            $issues[] = 'rto_above_mtpd';
        }
        $unacceptable = self::firstUnacceptableHorizon($grid);
        if ($mtpd > 0 && $unacceptable > 0 && $mtpd > $unacceptable) {
            $issues[] = 'mtpd_above_impact';
        }
        if ($rpo > 0 && $mtpd > 0 && $rpo > $mtpd) {
            $issues[] = 'rpo_above_mtpd';
        }
        if (self::isDecreasing($grid)) {
            $issues[] = 'impact_decreasing';
        }

        return $issues;
    }

    /**
     * @param array<int, array<string, int>> $grid
     */
    public static function isDecreasing(array $grid): bool
    {
        ksort($grid);
        foreach (self::CATEGORIES as $category) {
            $previous = 0;
            foreach ($grid as $levels) {
                $level = $levels[$category] ?? 0;
                if ($level > 0 && $level < $previous) {
                    return true;
                }
                $previous = max($previous, $level);
            }
        }

        return false;
    }

    /**
     * Actifs dont dépend le processus et qui ne tiennent pas ses objectifs. Codes :
     * - rto_missing : RTO de l'actif non défini alors que le processus en a un ;
     * - rto_slower : l'actif reprend plus lentement que le processus qui en dépend ;
     * - rpo_larger : l'actif peut perdre plus de données que le processus ne l'admet.
     *
     * @param array<string, array{rto: int, rpo: int}> $assets clé d'élément => objectifs (0 : non défini)
     * @return list<array{key: string, issue: string, required: int, actual: int}>
     */
    public static function dependencyIssues(int $rto, int $rpo, array $assets): array
    {
        $issues = [];
        foreach ($assets as $key => $objectives) {
            if ($rto > 0) {
                if ($objectives['rto'] <= 0) {
                    $issues[] = ['key' => (string) $key, 'issue' => 'rto_missing', 'required' => $rto, 'actual' => 0];
                } elseif ($objectives['rto'] > $rto) {
                    $issues[] = [
                        'key'      => (string) $key,
                        'issue'    => 'rto_slower',
                        'required' => $rto,
                        'actual'   => $objectives['rto'],
                    ];
                }
            }
            if ($rpo > 0 && $objectives['rpo'] > $rpo) {
                $issues[] = [
                    'key' => (string) $key, 'issue' => 'rpo_larger', 'required' => $rpo, 'actual' => $objectives['rpo'],
                ];
            }
        }

        return $issues;
    }
}

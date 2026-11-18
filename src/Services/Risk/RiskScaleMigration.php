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
 * Issue #706 : conversion d'un registre de risques d'une taille de matrice à une autre, selon la
 * correspondance validée par l'administrateur. PHP pur : le calcul de l'aperçu et celui de
 * l'application sont le même, l'écran ne peut donc pas annoncer autre chose que ce qui sera fait.
 */
final class RiskScaleMigration
{
    /**
     * Nouvelles valeurs d'un risque, limitées aux champs qui changent (tableau vide : rien à écrire).
     * Une valeur absente de la correspondance (donnée ancienne hors échelle) est gardée telle quelle ;
     * une probabilité ou un impact résiduel vide (non évalué) reste vide.
     *
     * @param array<string, mixed> $row champs du risque (probability, impact, risk_level, computed_score,
     *     et, si $withResidual, residual_probability, residual_impact, residual_level)
     * @param array{probability: array<string, string>, impact: array<string, string>} $mapping
     * @return array<string, string|float>
     */
    public static function convertRow(
        array $row,
        array $mapping,
        RiskScoringService $scoring,
        bool $withResidual
    ): array {
        $probability = self::map((string) ($row['probability'] ?? ''), $mapping['probability']);
        $impact = self::map((string) ($row['impact'] ?? ''), $mapping['impact']);
        $new = [
            'probability'    => $probability,
            'impact'         => $impact,
            'risk_level'     => $scoring->level($probability, $impact),
            'computed_score' => $scoring->score($probability, $impact),
        ];
        if ($withResidual) {
            $residualProbability = self::map((string) ($row['residual_probability'] ?? ''), $mapping['probability']);
            $residualImpact = self::map((string) ($row['residual_impact'] ?? ''), $mapping['impact']);
            $new['residual_probability'] = $residualProbability;
            $new['residual_impact'] = $residualImpact;
            $new['residual_level'] = $residualProbability !== '' && $residualImpact !== ''
                ? $scoring->level($residualProbability, $residualImpact)
                : '';
        }

        $changes = [];
        foreach ($new as $field => $value) {
            $old = $row[$field] ?? '';
            $differs = $field === 'computed_score'
                ? abs((float) $old - (float) $value) > 0.001
                : (string) $old !== (string) $value;
            if ($differs) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /**
     * Ce que la conversion changera, pour l'écran de validation : nombre de risques modifiés, et
     * passages d'un niveau à un autre (initial et résiduel), par niveau de départ puis d'arrivée.
     *
     * @param list<array<string, mixed>> $rows
     * @param array{probability: array<string, string>, impact: array<string, string>} $mapping
     * @return array{total: int, changed: int, raised: int, lowered: int,
     *     level_moves: array<string, array<string, int>>, residual_moves: array<string, array<string, int>>}
     */
    public static function summarize(
        array $rows,
        array $mapping,
        RiskScoringService $scoring,
        bool $withResidual
    ): array {
        $summary = [
            'total' => count($rows), 'changed' => 0, 'raised' => 0, 'lowered' => 0,
            'level_moves' => [], 'residual_moves' => [],
        ];
        foreach ($rows as $row) {
            $changes = self::convertRow($row, $mapping, $scoring, $withResidual);
            if ($changes === []) {
                continue;
            }
            $summary['changed']++;
            if (isset($changes['risk_level'])) {
                $from = (string) ($row['risk_level'] ?? '');
                $to = (string) $changes['risk_level'];
                $summary['level_moves'][$from][$to] = ($summary['level_moves'][$from][$to] ?? 0) + 1;
                $direction = self::rank($to) <=> self::rank($from);
                if ($direction > 0) {
                    $summary['raised']++;
                } elseif ($direction < 0) {
                    $summary['lowered']++;
                }
            }
            if (isset($changes['residual_level'])) {
                $from = (string) ($row['residual_level'] ?? '');
                $to = (string) $changes['residual_level'];
                $summary['residual_moves'][$from][$to] = ($summary['residual_moves'][$from][$to] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /**
     * Additionne deux résumés (registre des risques et registre des risques fournisseurs).
     *
     * @param array{total: int, changed: int, raised: int, lowered: int,
     *     level_moves: array<string, array<string, int>>, residual_moves: array<string, array<string, int>>} $a
     * @param array{total: int, changed: int, raised: int, lowered: int,
     *     level_moves: array<string, array<string, int>>, residual_moves: array<string, array<string, int>>} $b
     * @return array{total: int, changed: int, raised: int, lowered: int,
     *     level_moves: array<string, array<string, int>>, residual_moves: array<string, array<string, int>>}
     */
    public static function merge(array $a, array $b): array
    {
        foreach (['total', 'changed', 'raised', 'lowered'] as $key) {
            $a[$key] += $b[$key];
        }
        foreach (['level_moves', 'residual_moves'] as $key) {
            foreach ($b[$key] as $from => $targets) {
                foreach ($targets as $to => $count) {
                    $a[$key][$from][$to] = ($a[$key][$from][$to] ?? 0) + $count;
                }
            }
        }

        return $a;
    }

    /**
     * @param array<string, string> $axis
     */
    private static function map(string $value, array $axis): string
    {
        return $value === '' ? '' : ($axis[$value] ?? $value);
    }

    private static function rank(string $level): int
    {
        $index = array_search($level, RiskScale::LEVELS, true);

        return $index === false ? -1 : $index;
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Perimeter;

/**
 * Issue #536 : règles des périmètres (entités GLPI), en PHP pur.
 *
 * Une entité suit la SoA de l'entité la plus proche, elle-même ou un de ses parents, qui tient sa
 * propre SoA ; l'entité racine (0) tient toujours la SoA commune. L'arbre des entités est donné
 * par la table des parents (id de l'entité => id de son parent).
 */
final class PerimeterRules
{
    /**
     * L'entité puis ses parents, du plus proche à la racine. Une boucle dans l'arbre (donnée
     * abîmée) arrête la remontée au lieu de tourner sans fin.
     *
     * @param array<int, int> $parents id de l'entité => id du parent
     * @return list<int>
     */
    public static function chain(int $entityId, array $parents): array
    {
        $chain = [];
        $current = $entityId;
        while ($current >= 0 && !in_array($current, $chain, true)) {
            $chain[] = $current;
            if ($current === 0 || !isset($parents[$current])) {
                break;
            }
            $current = $parents[$current];
        }

        return $chain;
    }

    /**
     * Entité dont la SoA s'applique : la plus proche de la chaîne qui en tient une, sinon la racine.
     *
     * @param list<int> $chain voir chain()
     * @param list<int> $withOwnSoa entités qui tiennent leur propre SoA
     */
    public static function soaEntity(array $chain, array $withOwnSoa): int
    {
        foreach ($chain as $entityId) {
            if (in_array($entityId, $withOwnSoa, true)) {
                return $entityId;
            }
        }

        return 0;
    }

    /**
     * L'entité et toutes ses sous-entités.
     *
     * @param array<int, int> $parents id de l'entité => id du parent
     * @return list<int>
     */
    public static function subtree(int $entityId, array $parents): array
    {
        $children = [];
        foreach ($parents as $child => $parent) {
            if ($child !== $parent) {
                $children[$parent][] = $child;
            }
        }
        $subtree = [];
        $queue = [$entityId];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (in_array($current, $subtree, true)) {
                continue;
            }
            $subtree[] = $current;
            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $subtree;
    }

    /**
     * Enfants directs de l'entité.
     *
     * @param array<int, int> $parents id de l'entité => id du parent
     * @return list<int>
     */
    public static function children(int $entityId, array $parents): array
    {
        $children = [];
        foreach ($parents as $child => $parent) {
            if ($parent === $entityId && $child !== $entityId) {
                $children[] = $child;
            }
        }
        sort($children);

        return $children;
    }

    /**
     * Risques ouverts par niveau, et leur total.
     *
     * @param list<array{risk_level: string, status: string}> $risks
     * @return array{low: int, medium: int, high: int, critical: int, open: int, total: int}
     */
    public static function riskCounts(array $risks): array
    {
        $counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0, 'open' => 0, 'total' => 0];
        foreach ($risks as $risk) {
            $counts['total']++;
            if (!in_array($risk['status'], ['identified', 'in_treatment'], true)) {
                continue;
            }
            $counts['open']++;
            $level = $risk['risk_level'];
            if (in_array($level, ['low', 'medium', 'high', 'critical'], true)) {
                $counts[$level]++;
            }
        }

        return $counts;
    }
}

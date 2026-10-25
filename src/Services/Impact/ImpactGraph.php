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

namespace GlpiPlugin\Grcmanager\Services\Impact;

/**
 * Issue #177 : parcours du graphe d'impact natif de GLPI (relation « la panne de la source impacte
 * l'élément impacté »), sans base. Un nœud est noté « Itemtype:id ».
 */
final class ImpactGraph
{
    /** Profondeur maximale du parcours (garde-fou contre un graphe très étendu). */
    public const MAX_DEPTH = 10;

    public static function key(string $itemtype, int $itemsId): string
    {
        return $itemtype . ':' . $itemsId;
    }

    /**
     * @return array{0: string, 1: int}
     */
    public static function split(string $key): array
    {
        $pos = (int) strrpos($key, ':');

        return [substr($key, 0, $pos), (int) substr($key, $pos + 1)];
    }

    /**
     * Tout ce qui dépend de $start, directement ou non, avec la distance (1 = dépendance directe).
     * Les cycles sont sans effet : chaque nœud n'est compté qu'une fois, à sa plus courte distance.
     *
     * @param list<array{0: string, 1: string}> $edges [source, impacté]
     * @return array<string, int>
     */
    public static function downstream(array $edges, string $start): array
    {
        $children = [];
        foreach ($edges as [$source, $impacted]) {
            $children[$source][] = $impacted;
        }
        $depths = [];
        $frontier = [$start];
        for ($depth = 1; $frontier !== [] && $depth <= self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($children[$node] ?? [] as $child) {
                    if ($child === $start || isset($depths[$child])) {
                        continue;
                    }
                    $depths[$child] = $depth;
                    $next[] = $child;
                }
            }
            $frontier = $next;
        }

        return $depths;
    }

    /**
     * Points de défaillance uniques : pour chaque élément critique, s'il ne dépend que d'une seule
     * source directe, cette source est un point de défaillance unique pour lui.
     *
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string> $critical
     * @return list<array{source: string, critical: string}>
     */
    public static function singlePointsOfFailure(array $edges, array $critical): array
    {
        $sources = [];
        foreach ($edges as [$source, $impacted]) {
            if ($source !== $impacted) {
                $sources[$impacted][$source] = true;
            }
        }
        $points = [];
        foreach ($critical as $node) {
            if (isset($sources[$node]) && count($sources[$node]) === 1) {
                $points[] = ['source' => (string) array_key_first($sources[$node]), 'critical' => $node];
            }
        }

        return $points;
    }
}

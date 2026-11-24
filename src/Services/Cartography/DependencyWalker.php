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

namespace GlpiPlugin\Grcmanager\Services\Cartography;

/**
 * Issue #1082 : parcours de la cartographie à partir d'un élément, en largeur, sans base de données :
 * la fonction des voisins est fournie par l'appelant (sources GLPI et GRC, ou tableau en test).
 *
 * - vers le haut (UP) : tout ce que la panne de l'élément touche, jusqu'aux processus métier ;
 * - vers le bas (DOWN) : tout ce dont l'élément dépend, jusqu'au réseau.
 *
 * Les liens sont toujours rendus dans le sens « support → dépendant », quel que soit le parcours.
 */
final class DependencyWalker
{
    public const UP = 'up';
    public const DOWN = 'down';

    /** Au-delà, le parcours s'arrête et le signale (vue lisible, temps de réponse borné). */
    public const MAX_NODES = 200;

    /**
     * @param callable(string): list<string> $next voisins d'une clé dans le sens du parcours
     * @return array{nodes: array<string, int>, edges: list<array{0: string, 1: string}>, truncated: bool}
     *     nodes : clé => profondeur (0 pour le départ) ; edges : [support, dépendant]
     */
    public static function walk(string $start, string $direction, callable $next, int $limit = self::MAX_NODES): array
    {
        $nodes = [$start => 0];
        $edges = [];
        $seenEdges = [];
        $queue = [$start];
        $truncated = false;
        while ($queue !== []) {
            $key = array_shift($queue);
            foreach (array_unique($next($key)) as $neighbour) {
                if ($neighbour === $key) {
                    continue;
                }
                if (!isset($nodes[$neighbour])) {
                    if (count($nodes) >= $limit) {
                        $truncated = true;
                        continue;
                    }
                    $nodes[$neighbour] = $nodes[$key] + 1;
                    $queue[] = $neighbour;
                }
                $edge = $direction === self::DOWN ? [$neighbour, $key] : [$key, $neighbour];
                $id = $edge[0] . '>' . $edge[1];
                if (!isset($seenEdges[$id])) {
                    $seenEdges[$id] = true;
                    $edges[] = $edge;
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'truncated' => $truncated];
    }

    /**
     * Éléments atteints rangés par couche, puis par profondeur et par clé (ordre stable).
     *
     * @param array<string, int> $nodes clé => profondeur
     * @param callable(string): int $layerOf
     * @return array<int, list<string>> couche => clés
     */
    public static function byLayer(array $nodes, callable $layerOf): array
    {
        $layers = array_fill_keys(Layers::ALL, []);
        $keys = array_keys($nodes);
        usort($keys, static fn (string $a, string $b): int => [$nodes[$a], $a] <=> [$nodes[$b], $b]);
        foreach ($keys as $key) {
            $layers[$layerOf($key)][] = $key;
        }

        return $layers;
    }

    /**
     * Processus touchés, du plus urgent au moins urgent : RTO croissant, RTO non défini (0) en
     * dernier, puis par profondeur.
     *
     * @param array<string, int> $nodes clé => profondeur
     * @param array<string, int> $rto clé => RTO en heures (0 : non défini)
     * @return list<string>
     */
    public static function byRecoveryTime(array $nodes, array $rto): array
    {
        $keys = array_keys($nodes);
        $rank = static fn (string $key): array => [
            ($rto[$key] ?? 0) > 0 ? 0 : 1,
            $rto[$key] ?? 0,
            $nodes[$key],
            $key,
        ];
        usort($keys, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return $keys;
    }
}

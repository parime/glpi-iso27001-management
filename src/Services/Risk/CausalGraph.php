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
 * Issue #171 : effet domino entre risques et incidents. Chaque lien va d'une cause vers sa
 * conséquence ; les nœuds sont des clés « Itemtype:id ». Aucun calcul de probabilité : c'est une
 * représentation, le registre utilise des niveaux et non des probabilités chiffrées.
 */
final class CausalGraph
{
    /**
     * Nœuds atteignables depuis $start, vers les conséquences ($forward) ou vers les causes, avec
     * leur distance. Les cycles éventuels sont neutralisés.
     *
     * @param list<array{0: string, 1: string}> $edges
     * @return array<string, int>
     */
    public static function reachable(array $edges, string $start, int $maxDepth, bool $forward): array
    {
        $next = [];
        foreach ($edges as [$cause, $effect]) {
            if ($forward) {
                $next[$cause][] = $effect;
            } else {
                $next[$effect][] = $cause;
            }
        }
        $depths = [];
        $frontier = [$start];
        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; $depth++) {
            $following = [];
            foreach ($frontier as $node) {
                foreach ($next[$node] ?? [] as $neighbour) {
                    if ($neighbour === $start || isset($depths[$neighbour])) {
                        continue;
                    }
                    $depths[$neighbour] = $depth;
                    $following[] = $neighbour;
                }
            }
            $frontier = $following;
        }

        return $depths;
    }

    /**
     * Le lien cause → conséquence fermerait-il une boucle (la conséquence mène déjà à la cause) ?
     *
     * @param list<array{0: string, 1: string}> $edges
     */
    public static function wouldCreateCycle(array $edges, string $cause, string $effect): bool
    {
        return $cause === $effect || isset(self::reachable($edges, $effect, PHP_INT_MAX, true)[$cause]);
    }

    /**
     * Sous-graphe centré sur un nœud : ses causes et conséquences sur $depth niveaux, et les liens
     * entre ces nœuds.
     *
     * @param list<array{0: string, 1: string}> $edges
     * @return array{nodes: list<string>, edges: list<array{0: string, 1: string}>}
     */
    public static function around(array $edges, string $center, int $depth): array
    {
        $nodes = array_merge(
            [$center],
            array_keys(self::reachable($edges, $center, $depth, false)),
            array_keys(self::reachable($edges, $center, $depth, true))
        );
        $kept = array_flip($nodes);

        return [
            'nodes' => array_values(array_unique($nodes)),
            'edges' => array_values(array_filter(
                $edges,
                static fn (array $edge): bool => isset($kept[$edge[0]], $kept[$edge[1]])
            )),
        ];
    }
}

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
 * Issue #377 : dépendances entre processus métier, sans base.
 *
 * Une arête [amont, aval] signifie « le processus aval a besoin du processus amont » (même sens que
 * les arêtes [source, impacté] de l'analyse d'impact de GLPI, voir ImpactGraph). Un processus amont
 * doit reprendre au moins aussi vite que chacun des processus qui en dépendent, directement ou non :
 * son objectif exigé est le plus court RTO (et le plus court RPO) de ces processus.
 */
final class ProcessDependencyRules
{
    /**
     * Vrai si ajouter l'arête [$upstream, $dependent] créerait un cycle (un processus dont il
     * dépendrait lui-même, directement ou non). Parcours complet, sans limite de profondeur.
     *
     * @param list<array{0: int, 1: int}> $edges [amont, aval]
     */
    public static function wouldCreateCycle(array $edges, int $dependent, int $upstream): bool
    {
        if ($dependent === $upstream) {
            return true;
        }
        // Cycle si $dependent est déjà, directement ou non, en amont de $upstream.
        $parents = [];
        foreach ($edges as [$from, $to]) {
            $parents[(int) $to][] = (int) $from;
        }
        $seen = [$upstream => true];
        $stack = [$upstream];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($parents[$node] ?? [] as $parent) {
                if ($parent === $dependent) {
                    return true;
                }
                if (!isset($seen[$parent])) {
                    $seen[$parent] = true;
                    $stack[] = $parent;
                }
            }
        }

        return false;
    }

    /**
     * Objectifs exigés d'un processus par ceux qui en dépendent : le plus court RTO et le plus court
     * RPO définis parmi eux (0 : aucun), et le processus qui les impose.
     *
     * @param array<int, array{rto: int, rpo: int}> $dependents processus aval => objectifs
     * @return array{rto: int, rto_from: int, rpo: int, rpo_from: int}
     */
    public static function requiredObjectives(array $dependents): array
    {
        $required = ['rto' => 0, 'rto_from' => 0, 'rpo' => 0, 'rpo_from' => 0];
        foreach ($dependents as $id => $objectives) {
            foreach (['rto', 'rpo'] as $field) {
                $value = (int) ($objectives[$field] ?? 0);
                if ($value > 0 && ($required[$field] === 0 || $value < $required[$field])) {
                    $required[$field] = $value;
                    $required[$field . '_from'] = (int) $id;
                }
            }
        }

        return $required;
    }

    /**
     * Écarts des processus amont face aux objectifs du processus qui en dépend. Mêmes règles que
     * pour les actifs (BusinessImpactRules::dependencyIssues()), « RTO non défini » visant ici le
     * processus amont.
     *
     * @param array<int, array{rto: int, rpo: int}> $upstream processus amont => objectifs
     * @return array<int, list<string>> processus amont => codes
     */
    public static function upstreamIssues(int $rto, int $rpo, array $upstream): array
    {
        $issues = [];
        $byKey = [];
        foreach ($upstream as $id => $objectives) {
            $byKey['p' . $id] = ['rto' => (int) $objectives['rto'], 'rpo' => (int) $objectives['rpo']];
            $issues[(int) $id] = [];
        }
        foreach (BusinessImpactRules::dependencyIssues($rto, $rpo, $byKey) as $issue) {
            $issues[(int) substr($issue['key'], 1)][] = $issue['issue'] === 'rto_missing'
                ? 'process_rto_missing' : $issue['issue'];
        }

        return $issues;
    }
}

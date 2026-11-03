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

namespace GlpiPlugin\Grcmanager\Services\Network;

/**
 * Issue #144, phase 3 : vue zone × zone de la matrice des flux. Chaque flux actif est compté dans
 * la case (zone source, zone destination) ; la case prend la couleur du pire contrôle de ses flux.
 * Regroupé par préfixe (« SITE_A_SRV_BDD » → « SITE_A »… au premier séparateur), la même vue donne
 * la matrice entre sites.
 */
final class NetworkFlowMatrix
{
    public const GROUP_ZONE = 'zone';
    public const GROUP_PREFIX = 'prefix';

    /** Au-delà, la vue n'est plus lisible : elle est tronquée et le signale. */
    public const MAX_ZONES = 60;

    public const LEVEL_OK = 0;
    public const LEVEL_WARNING = 1;
    public const LEVEL_BLOCKING = 2;

    /**
     * @param list<array{id: int, source: string, destination: string, checks: list<string>}> $flows
     *     zones déjà résolues (libellé lisible de chaque extrémité) et contrôles déjà calculés
     * @return array{sources: list<string>, destinations: list<string>,
     *     cells: array<string, array<string, array{count: int, level: int, ids: list<int>}>>,
     *     truncated: bool, total: int}
     */
    public static function build(array $flows, string $group = self::GROUP_ZONE): array
    {
        $cells = [];
        $sources = [];
        $destinations = [];
        foreach ($flows as $flow) {
            $source = self::zone($flow['source'], $group);
            $destination = self::zone($flow['destination'], $group);
            $level = self::LEVEL_OK;
            foreach ($flow['checks'] as $check) {
                $level = max($level, NetworkFlowRules::isBlocking($check) ? self::LEVEL_BLOCKING : self::LEVEL_WARNING);
            }
            $cell = $cells[$source][$destination] ?? ['count' => 0, 'level' => self::LEVEL_OK, 'ids' => []];
            $cell['count']++;
            $cell['level'] = max($cell['level'], $level);
            $cell['ids'][] = (int) $flow['id'];
            $cells[$source][$destination] = $cell;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $destinations[$destination] = ($destinations[$destination] ?? 0) + 1;
        }

        // Zones les plus chargées d'abord, puis par ordre alphabétique ; au-delà du plafond, tronqué.
        $order = static function (array $counts): array {
            uksort($counts, static fn ($a, $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

            return array_map('strval', array_keys($counts));
        };
        $sourceList = $order($sources);
        $destinationList = $order($destinations);
        $truncated = count($sourceList) > self::MAX_ZONES || count($destinationList) > self::MAX_ZONES;

        return [
            'sources'      => array_slice($sourceList, 0, self::MAX_ZONES),
            'destinations' => array_slice($destinationList, 0, self::MAX_ZONES),
            'cells'        => $cells,
            'truncated'    => $truncated,
            'total'        => count($flows),
        ];
    }

    /**
     * Zone d'une extrémité : le libellé sans le réseau entre parenthèses (« SITE_A_WS_IT
     * (192.0.2.0/24) » → « SITE_A_WS_IT ») ; regroupé par préfixe, la partie avant le premier
     * « _ », « - » ou espace. « Internet », « Tous » et les libellés vides restent tels quels.
     */
    public static function zone(string $label, string $group = self::GROUP_ZONE): string
    {
        $zone = trim((string) preg_replace('/\s*\([^()]*\)\s*$/', '', trim($label)));
        if ($zone === '') {
            return '?';
        }
        if ($group === self::GROUP_PREFIX && !in_array($zone, ['Internet', 'Tous'], true)) {
            $parts = preg_split('/[_\-\s]/', $zone, 2) ?: [$zone];
            $zone = $parts[0] !== '' ? $parts[0] : $zone;
        }

        return $zone;
    }
}
